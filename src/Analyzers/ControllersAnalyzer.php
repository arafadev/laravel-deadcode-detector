<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Array_;
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
use Arafa\DeadcodeDetector\Support\DependencyGraphEngine;
use Arafa\DeadcodeDetector\Support\ExtendsImplementsAndTraitsIndex;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;
use Arafa\DeadcodeDetector\Support\PathExcludeMatcher;

class ControllersAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly PhpFileScanner $scanner,
        private readonly array $scanPaths,
        private readonly PathExcludeMatcher $pathExclude,
    ) {}

    /**
     * @return list<string>
     */
    public static function defaultScanPaths(): array
    {
        if (! function_exists('app_path')) {
            return [];
        }

        return [app_path('Http/Controllers')];
    }

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

        /**
         * Same short class name in different namespaces (e.g. two CartController classes)
         * must all receive route bindings; a single "first wins" map causes false positives.
         *
         * @var array<string, list<string>> lower short basename => list of FQCN
         */
        $shortBasenameToFqcns = [];
        $fileMeta             = [];

        foreach ($controllerFiles as $file) {
            $fqcn = $this->extractClassNameFromFile($file);
            if ($fqcn === null) {
                continue;
            }
            $short = strtolower(class_basename($fqcn));
            $shortBasenameToFqcns[$short][] = $fqcn;
            $real = $file->getRealPath();
            if ($real !== false) {
                $fileMeta[$real] = ['file' => $file, 'fqcn' => $fqcn, 'short' => $short];
            }
        }

        $refCollector = new ControllerRouteReferenceCollector();
        $actionExtractor = new ControllerRouteActionExtractor($shortBasenameToFqcns);

        foreach ($this->iterateRoutePhpFilesOnly() as $path) {
            $this->traverseWithVisitors($path, $refCollector, $actionExtractor);
        }

        $usedActions = $actionExtractor->getUsedActions();
        $hierarchyTargets = ExtendsImplementsAndTraitsIndex::build(
            $this->scanner,
            $this->scanPaths,
            $this->pathExclude,
        );

        /** @var array<string, true> */
        $controllerFqcnLowerLookup = [];
        foreach ($fileMeta as $meta) {
            $controllerFqcnLowerLookup[strtolower(ltrim($meta['fqcn'], '\\'))] = true;
        }

        $globallyCalledControllerMethods = $this->collectGloballyCalledControllerMethods(
            $controllerFqcnLowerLookup,
            $shortBasenameToFqcns,
        );

        $dependencyGraph = DependencyGraphEngine::getOrBuild($this->scanner, $this->scanPaths, $this->pathExclude);
        $dependencyGraph->mergeReachableControllerMethodsFromRoutesInto(
            $globallyCalledControllerMethods,
            $controllerFqcnLowerLookup,
        );

        $dynamicDispatch           = $this->collectDynamicControllerDispatch(
            $controllerFqcnLowerLookup,
            $shortBasenameToFqcns,
        );
        $dynamicTouchedFqcn        = $dynamicDispatch['touched'];
        $dynamicDispatchedMethods  = $dynamicDispatch['methods'];
        foreach ($dynamicDispatchedMethods as $k => $_) {
            $globallyCalledControllerMethods[$k] = true;
        }

        $results     = [];

        foreach ($fileMeta as $meta) {
            $file    = $meta['file'];
            $fqcn    = $meta['fqcn'];
            $keyBase = strtolower(ltrim($fqcn, '\\'));

            $routeReferenced = $refCollector->isControllerReferenced($fqcn, class_basename($fqcn));
            $hierarchyReferenced = isset($hierarchyTargets[$keyBase]);
            $dynamicTouched      = isset($dynamicTouchedFqcn[$keyBase]);

            if (! $routeReferenced && ! $hierarchyReferenced && ! $dynamicTouched) {
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
                if (isset($globallyCalledControllerMethods[$k])) {
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
     * Controller class / route-action bindings must come only from route files so a
     * controller referenced from services only is not treated as "routed".
     *
     * @return \Generator<string>
     */
    private function iterateRoutePhpFilesOnly(): \Generator
    {
        $routesDir = base_path('routes');
        if (is_dir($routesDir)) {
            foreach ($this->scanner->scanDirectoryLazy($routesDir) as $file) {
                $real = $file->getRealPath();
                if ($real !== false && ! $this->isExcluded($real)) {
                    yield $real;
                }
            }
        }
    }

    /**
     * PHP files scanned for cross-controller (and project-wide) action calls.
     *
     * @return \Generator<string>
     */
    private function iterateAllProjectPhpFiles(): \Generator
    {
        $bases = $this->scanPaths;
        $app   = function_exists('app_path') ? app_path() : '';
        if ($app !== '' && ! in_array($app, $bases, true)) {
            $bases[] = $app;
        }

        foreach ($bases as $basePath) {
            if ($basePath === '' || $basePath === false) {
                continue;
            }
            foreach ($this->scanner->scanDirectoryLazy($basePath) as $file) {
                $real = $file->getRealPath();
                if ($real !== false && ! $this->isExcluded($real)) {
                    yield $real;
                }
            }
        }

        foreach (['routes', 'config', 'database', 'bootstrap'] as $dir) {
            $dirPath = base_path($dir);
            if (! is_dir($dirPath)) {
                continue;
            }
            foreach ($this->scanner->scanDirectoryLazy($dirPath) as $file) {
                $real = $file->getRealPath();
                if ($real !== false && ! $this->isExcluded($real)) {
                    yield $real;
                }
            }
        }
    }

    /**
     * @param array<string, true>              $controllerFqcnLowerLookup
     * @param array<string, list<string>>     $shortBasenameToFqcns
     *
     * @return array<string, true> keys: strtolower(fqcn)::method
     */
    private function collectGloballyCalledControllerMethods(
        array $controllerFqcnLowerLookup,
        array $shortBasenameToFqcns,
    ): array {
        $used    = [];
        $visitor = new GlobalControllerMethodCallVisitor(
            $controllerFqcnLowerLookup,
            $shortBasenameToFqcns,
            $used,
        );

        foreach ($this->iterateAllProjectPhpFiles() as $path) {
            $stmts = $this->parseStatements($path);
            if ($stmts === null) {
                continue;
            }

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($visitor);
            $traverser->traverse($stmts);
        }

        return $used;
    }

    /**
     * @param array<string, true>            $controllerFqcnLowerLookup
     * @param array<string, list<string>>  $shortBasenameToFqcns
     *
     * @return array{touched: array<string, true>, methods: array<string, true>}
     */
    private function collectDynamicControllerDispatch(
        array $controllerFqcnLowerLookup,
        array $shortBasenameToFqcns,
    ): array {
        /** @var array<string, true> */
        $touched = [];
        /** @var array<string, true> */
        $methods = [];

        $visitor = new ControllerDynamicDispatchVisitor(
            $controllerFqcnLowerLookup,
            $shortBasenameToFqcns,
            $touched,
            $methods,
        );

        foreach ($this->iterateAllProjectPhpFiles() as $path) {
            $stmts = $this->parseStatements($path);
            if ($stmts === null) {
                continue;
            }

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($visitor);
            $traverser->traverse($stmts);
        }

        return ['touched' => $touched, 'methods' => $methods];
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
        $seenRealPaths  = [];

        foreach ($this->dedupedControllerDirectories() as $controllerDir) {
            foreach ($this->scanner->scanDirectoryLazy($controllerDir) as $file) {
                $real = $file->getRealPath();
                if ($real === false || $this->isExcluded($real) || isset($seenRealPaths[$real])) {
                    continue;
                }
                $seenRealPaths[$real] = true;
                $controllerFiles[]    = $file;
            }
        }

        return $controllerFiles;
    }

    /**
     * Laravel convention: app/Http/Controllers. Always include app_path-based controllers
     * in addition to scan_paths so misconfigured scans still see API/User controllers.
     *
     * @return list<string> absolute directory paths
     */
    private function dedupedControllerDirectories(): array
    {
        $dirs  = [];
        $bases = $this->scanPaths;
        $app = function_exists('app_path') ? app_path() : '';
        if ($app !== '' && ! in_array($app, $bases, true)) {
            $bases[] = $app;
        }

        foreach ($bases as $basePath) {
            if ($basePath === '' || $basePath === false) {
                continue;
            }
            $dirs[] = rtrim($basePath, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'Http'
                . DIRECTORY_SEPARATOR . 'Controllers';
        }

        $out   = [];
        $seenD = [];
        foreach ($dirs as $dir) {
            $real = realpath($dir);
            if ($real === false || isset($seenD[$real])) {
                continue;
            }
            $seenD[$real] = true;
            $out[]        = $real;
        }

        return $out;
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
        return $this->pathExclude->shouldExclude($path);
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

/**
 * Detects app()->call([new Controller, 'method']), App::call(...), and any new Controller()
 * for marking controllers/actions as used outside classic dispatch.
 */
final class ControllerDynamicDispatchVisitor extends NodeVisitorAbstract
{
    /**
     * @param array<string, true>           $controllerFqcnLowerLookup
     * @param array<string, list<string>>   $shortBasenameToFqcns
     * @param array<string, true>           $touchedFqcn
     * @param array<string, true>           $methodKeys
     */
    public function __construct(
        private readonly array $controllerFqcnLowerLookup,
        private readonly array $shortBasenameToFqcns,
        private array &$touchedFqcn,
        private array &$methodKeys,
    ) {}

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof New_ && $node->class !== null) {
            foreach ($this->resolveKnownControllerKeys($node->class) as $k) {
                $this->touchedFqcn[$k] = true;
            }
        }

        if ($node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->name === 'call') {
            if ($node->var instanceof FuncCall && $this->funcIsApp($node->var->name)) {
                $this->consumeCallArrayArg($node->args[0]->value ?? null);
            }
        }

        if ($node instanceof StaticCall
            && $node->name instanceof Identifier
            && strtolower($node->name->name) === 'call'
            && $this->classLooksLikeApp($node->class)) {
            $this->consumeCallArrayArg($node->args[0]->value ?? null);
        }

        return null;
    }

    private function consumeCallArrayArg(?Node $expr): void
    {
        if (! $expr instanceof Array_ || count($expr->items) < 2) {
            return;
        }

        $callee    = $expr->items[0]->value ?? null;
        $methodArg = $expr->items[1]->value ?? null;
        $method    = 'invoke';
        if ($methodArg instanceof String_) {
            $m = trim($methodArg->value);
            if ($m !== '') {
                $method = $m;
            }
        }

        $keys = [];
        if ($callee instanceof New_ && $callee->class !== null) {
            $keys = $this->resolveKnownControllerKeys($callee->class);
        } elseif ($callee instanceof ClassConstFetch
            && $callee->name instanceof Identifier
            && $callee->name->name === 'class') {
            $keys = $this->resolveKnownControllerKeys($callee->class);
        }

        foreach ($keys as $k) {
            $this->touchedFqcn[$k] = true;
            $this->methodKeys[$k . '::' . strtolower($method)] = true;
        }
    }

    private function funcIsApp(?Node $name): bool
    {
        if ($name instanceof Name) {
            return strtolower($name->getLast()) === 'app';
        }

        return $name instanceof FullyQualified && strtolower($name->getLast()) === 'app';
    }

    private function classLooksLikeApp(?Node $classExpr): bool
    {
        if ($classExpr instanceof Name || $classExpr instanceof FullyQualified) {
            return strcasecmp($classExpr->getLast(), 'App') === 0;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function resolveKnownControllerKeys(?Node $classExpr): array
    {
        if ($classExpr === null) {
            return [];
        }

        $keys = [];
        if ($classExpr instanceof FullyQualified) {
            $keys[] = strtolower(ltrim($classExpr->toString(), '\\'));
        } elseif ($classExpr instanceof Name) {
            $s = $classExpr->toString();
            if (str_contains($s, '\\')) {
                $keys[] = strtolower(ltrim($s, '\\'));
            } else {
                foreach ($this->shortBasenameToFqcns[strtolower($s)] ?? [] as $fqcn) {
                    $keys[] = strtolower(ltrim($fqcn, '\\'));
                }
            }
        }

        $out = [];
        foreach ($keys as $k) {
            if (isset($this->controllerFqcnLowerLookup[$k])) {
                $out[] = $k;
            }
        }

        return $out;
    }
}

/**
 * Marks controller public actions as used when called from anywhere in the project
 * (static calls, new Instance()->method(), app(Controller::class)->method()).
 */
final class GlobalControllerMethodCallVisitor extends NodeVisitorAbstract
{
    /**
     * @param array<string, true>           $controllerFqcnLowerLookup
     * @param array<string, list<string>>   $shortBasenameToFqcns
     * @param array<string, true>           $used
     */
    public function __construct(
        private readonly array $controllerFqcnLowerLookup,
        private readonly array $shortBasenameToFqcns,
        private array &$used,
    ) {}

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof StaticCall && $node->name instanceof Identifier) {
            foreach ($this->resolveKnownControllerKeys($node->class) as $key) {
                $this->used[$key . '::' . strtolower($node->name->name)] = true;
            }

            return null;
        }

        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            $method = strtolower($node->name->name);

            if ($node->var instanceof New_) {
                $cls = $node->var->class;
                if ($cls !== null) {
                    foreach ($this->resolveKnownControllerKeys($cls) as $key) {
                        $this->used[$key . '::' . $method] = true;
                    }
                }

                return null;
            }

            if ($node->var instanceof FuncCall && $this->funcCallIsApp($node->var)) {
                $arg0 = $node->var->args[0]->value ?? null;
                if ($arg0 instanceof ClassConstFetch
                    && $arg0->name instanceof Identifier
                    && $arg0->name->name === 'class') {
                    foreach ($this->resolveKnownControllerKeys($arg0->class) as $key) {
                        $this->used[$key . '::' . $method] = true;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return list<string> normalized lowercase FQCN keys present in the scanned controller set
     */
    private function resolveKnownControllerKeys(?Node $classExpr): array
    {
        if ($classExpr === null) {
            return [];
        }

        $keys = [];
        if ($classExpr instanceof FullyQualified) {
            $keys[] = strtolower(ltrim($classExpr->toString(), '\\'));
        } elseif ($classExpr instanceof Name) {
            $s = $classExpr->toString();
            if (str_contains($s, '\\')) {
                $keys[] = strtolower(ltrim($s, '\\'));
            } else {
                foreach ($this->shortBasenameToFqcns[strtolower($s)] ?? [] as $fqcn) {
                    $keys[] = strtolower(ltrim($fqcn, '\\'));
                }
            }
        }

        $out = [];
        foreach ($keys as $k) {
            if (isset($this->controllerFqcnLowerLookup[$k])) {
                $out[] = $k;
            }
        }

        return $out;
    }

    private function funcCallIsApp(FuncCall $node): bool
    {
        $n = $node->name;
        if ($n instanceof Name) {
            return strtolower($n->getLast()) === 'app';
        }

        if ($n instanceof FullyQualified) {
            return strtolower($n->getLast()) === 'app';
        }

        return false;
    }
}
