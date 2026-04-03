<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use SplFileInfo;
use Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Arafa\DeadcodeDetector\Support\AstParserFactory;
use Arafa\DeadcodeDetector\Support\ControllerRouteActionExtractor;
use Arafa\DeadcodeDetector\Support\ExtendsImplementsAndTraitsIndex;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;

class ControllersAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly PhpFileScanner $scanner,
        private readonly array $scanPaths,
        private readonly array $excludePaths = [],
    ) {}

    public function getName(): string
    {
        return 'controllers';
    }

    public function getDescription(): string
    {
        return 'Finds unused controller classes and unused public controller actions (not bound in routes).';
    }

    public function analyze(): array
    {
        $controllerFiles = $this->findControllerFiles();

        if ($controllerFiles === []) {
            return [];
        }

        /** @var array<string, string> short lower basename => FQCN (first wins) */
        $shortToFqcn = [];
        $fileMeta    = [];

        foreach ($controllerFiles as $file) {
            $fqcn = $this->extractClassNameFromFile($file);
            if ($fqcn === null) {
                continue;
            }
            $short = strtolower(class_basename($fqcn));
            if (! isset($shortToFqcn[$short])) {
                $shortToFqcn[$short] = $fqcn;
            }
            $real = $file->getRealPath();
            if ($real !== false) {
                $fileMeta[$real] = ['file' => $file, 'fqcn' => $fqcn, 'short' => $short];
            }
        }

        $refCollector = new ControllerRouteReferenceCollector();
        $actionExtractor = new ControllerRouteActionExtractor($shortToFqcn);

        foreach ($this->iterateRouteAndAppPhpFiles() as $path) {
            $this->traverseWithVisitors($path, $refCollector, $actionExtractor);
        }

        $usedActions = $actionExtractor->getUsedActions();
        $hierarchyTargets = ExtendsImplementsAndTraitsIndex::build(
            $this->scanner,
            $this->scanPaths,
            $this->excludePaths,
        );
        $results     = [];

        foreach ($fileMeta as $meta) {
            $file    = $meta['file'];
            $fqcn    = $meta['fqcn'];
            $keyBase = strtolower(ltrim($fqcn, '\\'));

            $routeReferenced = $refCollector->isControllerReferenced($fqcn, class_basename($fqcn));
            $hierarchyReferenced = isset($hierarchyTargets[$keyBase]);

            if (! $routeReferenced && ! $hierarchyReferenced) {
                $results[] = DeadCodeResult::fromArray([
                    'analyzerName'   => $this->getName(),
                    'type'           => 'controller',
                    'filePath'       => $file->getRealPath(),
                    'className'      => $fqcn,
                    'methodName'     => null,
                    'lastModified'   => date('Y-m-d H:i:s', $file->getMTime()),
                    'isSafeToDelete' => false,
                ]);

                continue;
            }

            if (! $routeReferenced) {
                continue;
            }

            $shortName       = class_basename($fqcn);
            $methods         = $this->extractPublicActionMethods($file, $shortName);
            $internalCalls   = $this->extractInternallyCalledMethodNames($file, $shortName);

            foreach ($methods as $methodName) {
                $k = $keyBase . '::' . strtolower($methodName);
                if (isset($internalCalls[strtolower($methodName)])) {
                    continue;
                }
                if (! isset($usedActions[$k])) {
                    $results[] = DeadCodeResult::fromArray([
                        'analyzerName'   => $this->getName(),
                        'type'           => 'controller_method',
                        'filePath'       => $file->getRealPath(),
                        'className'      => $fqcn,
                        'methodName'     => $methodName,
                        'lastModified'   => date('Y-m-d H:i:s', $file->getMTime()),
                        'isSafeToDelete' => false,
                    ]);
                }
            }
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function extractPublicActionMethods(SplFileInfo $file, string $classShortName): array
    {
        $path = $file->getRealPath();
        if ($path === false) {
            return [];
        }

        $stmts = $this->parseStatements($path);
        if ($stmts === null) {
            return [];
        }

        $visitor = new ControllerClassPublicMethodsVisitor($classShortName);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return $visitor->getMethods();
    }

    /**
     * Methods invoked from within the same controller class ($this->…, self::…, static::…).
     *
     * @return array<string, true> lowercase method name => true
     */
    private function extractInternallyCalledMethodNames(SplFileInfo $file, string $classShortName): array
    {
        $path = $file->getRealPath();
        if ($path === false) {
            return [];
        }

        $stmts = $this->parseStatements($path);
        if ($stmts === null) {
            return [];
        }

        $visitor = new ControllerInternalCallCollectorVisitor($classShortName);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return $visitor->getCalled();
    }

    private function traverseWithVisitors(
        string $path,
        ControllerRouteReferenceCollector $refCollector,
        ControllerRouteActionExtractor $actionExtractor,
    ): void {
        $stmts = $this->parseStatements($path);
        if ($stmts === null) {
            return;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor($refCollector);
        $traverser->addVisitor($actionExtractor);
        $traverser->traverse($stmts);
    }

    /**
     * @return \Generator<string>
     */
    private function iterateRouteAndAppPhpFiles(): \Generator
    {
        $routesDir = base_path('routes');
        if (is_dir($routesDir)) {
            foreach ($this->scanner->scanDirectory($routesDir) as $file) {
                $real = $file->getRealPath();
                if ($real !== false && ! $this->isExcluded($real)) {
                    yield $real;
                }
            }
        }

        foreach ($this->scanPaths as $basePath) {
            foreach ($this->scanner->scanDirectory($basePath) as $file) {
                $real = $file->getRealPath();
                if ($real !== false && ! $this->isExcluded($real)) {
                    yield $real;
                }
            }
        }
    }

    /**
     * @return \PhpParser\Node\Stmt[]|null
     */
    private function parseStatements(string $path): ?array
    {
        $code = @file_get_contents($path);
        if ($code === false) {
            return null;
        }

        try {
            return AstParserFactory::createParser()->parse($code);
        } catch (Error $e) {
            return null;
        }
    }

    /** @return SplFileInfo[] */
    private function findControllerFiles(): array
    {
        $controllerFiles = [];

        foreach ($this->scanPaths as $basePath) {
            $controllerDir = rtrim($basePath, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'Http'
                . DIRECTORY_SEPARATOR . 'Controllers';

            foreach ($this->scanner->scanDirectory($controllerDir) as $file) {
                $real = $file->getRealPath();
                if ($real !== false && ! $this->isExcluded($real)) {
                    $controllerFiles[] = $file;
                }
            }
        }

        return $controllerFiles;
    }

    private function extractClassNameFromFile(SplFileInfo $file): ?string
    {
        $path = $file->getRealPath();
        if ($path === false) {
            return null;
        }

        $stmts = $this->parseStatements($path);
        if ($stmts === null) {
            return null;
        }

        $namespace = null;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                $namespace = $stmt->name !== null ? $stmt->name->toString() : null;
                foreach ($stmt->stmts ?? [] as $inner) {
                    if ($inner instanceof Class_ && $inner->name !== null) {
                        $class = $inner->name->name;

                        return $namespace !== null && $namespace !== '' ? $namespace . '\\' . $class : $class;
                    }
                }
            } elseif ($stmt instanceof Class_ && $stmt->name !== null) {
                return $stmt->name->name;
            }
        }

        return null;
    }

    private function isExcluded(string $path): bool
    {
        foreach ($this->excludePaths as $exclude) {
            if (str_contains($path, $exclude)) {
                return true;
            }
        }

        return false;
    }
}

/**
 * Collects controller references from route-style AST nodes (String_, ClassConstFetch,
 * StaticCall, array tuple actions).
 */
final class ControllerRouteReferenceCollector extends NodeVisitorAbstract
{
    /** @var array<string, true> */
    private array $referencedFqcn = [];

    /** @var array<string, true> */
    private array $literalStrings = [];

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof String_) {
            $this->literalStrings[$node->value] = true;
            if (str_contains($node->value, '@')) {
                $before = explode('@', $node->value, 2)[0];
                if ($before !== '') {
                    $this->referencedFqcn[$before] = true;
                    $this->literalStrings[$before] = true;
                }
            }

            return null;
        }

        if ($node instanceof ClassConstFetch) {
            $fqcn = $this->classExprToString($node->class);
            if ($fqcn !== null) {
                $this->referencedFqcn[$fqcn] = true;
            }

            return null;
        }

        if ($node instanceof StaticCall) {
            $fqcn = $this->classExprToString($node->class);
            if ($fqcn !== null) {
                $this->referencedFqcn[$fqcn] = true;
            }
        }

        return null;
    }

    public function isControllerReferenced(string $fqcn, string $shortName): bool
    {
        if (isset($this->referencedFqcn[$fqcn])) {
            return true;
        }

        if (isset($this->literalStrings[$shortName])) {
            return true;
        }

        $normalized = ltrim($fqcn, '\\');
        if (isset($this->literalStrings[$normalized])) {
            return true;
        }

        foreach ($this->referencedFqcn as $ref => $_) {
            if (strcasecmp($ref, $fqcn) === 0 || strcasecmp(ltrim($ref, '\\'), $normalized) === 0) {
                return true;
            }
        }

        return false;
    }

    private function classExprToString(?Node $expr): ?string
    {
        if ($expr === null) {
            return null;
        }

        if ($expr instanceof FullyQualified) {
            return $expr->toString();
        }

        if ($expr instanceof Name) {
            return $expr->toString();
        }

        return null;
    }
}

/**
 * Public methods on the controller class that may correspond to route actions.
 *
 * @phpstan-type MethodList list<string>
 */
final class ControllerClassPublicMethodsVisitor extends NodeVisitorAbstract
{
    /** @var list<string> */
    private array $methods = [];

    public function __construct(
        private readonly string $classShortName,
    ) {}

    /**
     * @return list<string>
     */
    public function getMethods(): array
    {
        return $this->methods;
    }

    public function enterNode(Node $node): ?int
    {
        if (! $node instanceof Class_ || $node->name === null || $node->name->name !== $this->classShortName) {
            return null;
        }

        foreach ($node->stmts ?? [] as $stmt) {
            if (! $stmt instanceof ClassMethod) {
                continue;
            }

            if (! $stmt->isPublic()) {
                continue;
            }

            $name = $stmt->name->name;

            if ($name === '__construct') {
                continue;
            }

            if (str_starts_with($name, '_') && $name !== '__invoke') {
                continue;
            }

            if (in_array($name, ['middleware', 'callAction', 'authorize', 'authorizeResource', 'validate', 'validateWith', 'dispatch'], true)) {
                continue;
            }

            $this->methods[] = $name;
        }

        return NodeTraverser::DONT_TRAVERSE_CHILDREN;
    }
}

/**
 * Collects instance/static method names called from inside the target controller body
 * (not from nested classes).
 */
final class ControllerInternalCallCollectorVisitor extends NodeVisitorAbstract
{
    /** @var array<string, true> */
    private array $called = [];

    private bool $inOurClass = false;

    private int $skipNestedClassDepth = 0;

    public function __construct(
        private readonly string $classShortName,
    ) {}

    /**
     * @return array<string, true>
     */
    public function getCalled(): array
    {
        return $this->called;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Class_) {
            if ($this->skipNestedClassDepth > 0) {
                $this->skipNestedClassDepth++;

                return NodeTraverser::DONT_TRAVERSE_CHILDREN;
            }

            if ($this->inOurClass) {
                $this->skipNestedClassDepth = 1;

                return NodeTraverser::DONT_TRAVERSE_CHILDREN;
            }

            if ($node->name !== null && $node->name->name === $this->classShortName) {
                $this->inOurClass = true;
            }

            return null;
        }

        if (! $this->inOurClass || $this->skipNestedClassDepth > 0) {
            return null;
        }

        if ($node instanceof MethodCall && $this->isThisVar($node->var)) {
            if ($node->name instanceof Identifier) {
                $this->called[strtolower($node->name->name)] = true;
            }

            return null;
        }

        if ($node instanceof NullsafeMethodCall && $this->isThisVar($node->var)) {
            if ($node->name instanceof Identifier) {
                $this->called[strtolower($node->name->name)] = true;
            }

            return null;
        }

        if ($node instanceof StaticCall && $this->isSelfOrStatic($node->class)) {
            if ($node->name instanceof Identifier) {
                $this->called[strtolower($node->name->name)] = true;
            }
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Class_) {
            if ($this->skipNestedClassDepth > 0) {
                $this->skipNestedClassDepth--;

                return null;
            }

            if ($this->inOurClass && $node->name !== null && $node->name->name === $this->classShortName) {
                $this->inOurClass = false;
            }
        }

        return null;
    }

    private function isThisVar(Node $expr): bool
    {
        if (! $expr instanceof Variable) {
            return false;
        }

        $n = $expr->name;
        if (is_string($n)) {
            return $n === 'this';
        }

        if ($n instanceof Identifier) {
            return $n->name === 'this';
        }

        return false;
    }

    private function isSelfOrStatic(?Node $classExpr): bool
    {
        if ($classExpr === null) {
            return false;
        }

        if ($classExpr instanceof Name) {
            $lower = strtolower($classExpr->toString());

            return $lower === 'self' || $lower === 'static';
        }

        return false;
    }
}
