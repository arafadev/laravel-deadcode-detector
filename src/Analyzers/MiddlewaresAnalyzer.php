<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
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
use Arafa\DeadcodeDetector\Support\ExtendsImplementsAndTraitsIndex;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;

class MiddlewaresAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly PhpFileScanner $scanner,
        private readonly array $scanPaths,
        private readonly array $excludePaths = [],
    ) {}

    public function getName(): string
    {
        return 'middlewares';
    }

    public function getDescription(): string
    {
        return 'Finds middleware classes that are never registered or applied in routes/kernel.';
    }

    public function analyze(): array
    {
        $middlewareFiles = $this->findMiddlewareFiles();

        if ($middlewareFiles === []) {
            return [];
        }

        /** @var array<string, array{file: SplFileInfo, fqcn: string}> */
        $metaByNorm = [];

        foreach ($middlewareFiles as $file) {
            $fqcn = $this->extractClassNameFromFile($file);
            if ($fqcn === null) {
                continue;
            }
            $metaByNorm[$this->normalizeFqcn($fqcn)] = ['file' => $file, 'fqcn' => $fqcn];
        }

        $referenced = $this->collectReferencedMiddlewareKeys(array_keys($metaByNorm));

        $hierarchyTargets = ExtendsImplementsAndTraitsIndex::build(
            $this->scanner,
            $this->scanPaths,
            $this->excludePaths,
        );

        $results = [];

        foreach ($metaByNorm as $norm => $meta) {
            if (isset($referenced[$norm]) || isset($hierarchyTargets[$norm])) {
                continue;
            }

            $file = $meta['file'];

            $results[] = DeadCodeResult::fromArray([
                'analyzerName'   => $this->getName(),
                'type'           => 'middleware',
                'filePath'       => $file->getRealPath(),
                'className'      => $meta['fqcn'],
                'methodName'     => null,
                'lastModified'   => date('Y-m-d H:i:s', $file->getMTime()),
                'isSafeToDelete' => false,
            ]);
        }

        return $results;
    }

    /**
     * @param  string[] $normalizedFqcnKeys
     * @return array<string, true>
     */
    private function collectReferencedMiddlewareKeys(array $normalizedFqcnKeys): array
    {
        /** @var array<string, true> */
        $lookup = [];
        foreach ($normalizedFqcnKeys as $k) {
            $lookup[$k] = true;
        }

        $collector = new MiddlewareReferenceCollector($lookup);

        foreach ($this->iterateMiddlewareSourceFiles() as $path) {
            $stmts = $this->parseStatements($path);
            if ($stmts === null) {
                continue;
            }

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($collector);
            $traverser->traverse($stmts);
        }

        return $collector->getMatchedMiddleware();
    }

    /**
     * Kernel.php, bootstrap/app.php, routes, and controller PHP files.
     *
     * @return \Generator<string>
     */
    private function iterateMiddlewareSourceFiles(): \Generator
    {
        foreach ($this->scanPaths as $basePath) {
            $kernel = rtrim($basePath, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'Http'
                . DIRECTORY_SEPARATOR . 'Kernel.php';
            if (is_file($kernel) && ! $this->isExcluded($kernel)) {
                yield $kernel;
            }
        }

        $bootstrapApp = base_path('bootstrap/app.php');
        if (is_file($bootstrapApp) && ! $this->isExcluded($bootstrapApp)) {
            yield $bootstrapApp;
        }

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
            $ctrlDir = rtrim($basePath, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'Http'
                . DIRECTORY_SEPARATOR . 'Controllers';

            if (! is_dir($ctrlDir)) {
                continue;
            }

            foreach ($this->scanner->scanDirectory($ctrlDir) as $file) {
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
    private function findMiddlewareFiles(): array
    {
        $found = [];

        foreach ($this->scanPaths as $basePath) {
            $middlewareDir = rtrim($basePath, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'Http'
                . DIRECTORY_SEPARATOR . 'Middleware';

            if (! is_dir($middlewareDir)) {
                continue;
            }

            foreach ($this->scanner->scanDirectory($middlewareDir) as $file) {
                $real = $file->getRealPath();
                if ($real === false || $this->isExcluded($real)) {
                    continue;
                }

                if ($this->fileHasHandleMethod($file)) {
                    $found[] = $file;
                }
            }
        }

        return $found;
    }

    private function fileHasHandleMethod(SplFileInfo $file): bool
    {
        $path = $file->getRealPath();
        if ($path === false) {
            return false;
        }

        $stmts = $this->parseStatements($path);
        if ($stmts === null) {
            return false;
        }

        $visitor = new ClassMethodExistsVisitor('handle');
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return $visitor->found();
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

    private function normalizeFqcn(string $fqcn): string
    {
        return strtolower(ltrim($fqcn, '\\'));
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

final class ClassMethodExistsVisitor extends NodeVisitorAbstract
{
    private bool $found = false;

    public function __construct(
        private readonly string $methodName,
    ) {}

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof ClassMethod && $node->name->name === $this->methodName) {
            $this->found = true;

            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        return null;
    }

    public function found(): bool
    {
        return $this->found;
    }
}

/** Marks middleware classes referenced via ClassConstFetch, strings, calls, and attributes. */
final class MiddlewareReferenceCollector extends NodeVisitorAbstract
{
    /** @var array<string, true> */
    private array $targets;

    /** @var array<string, true> */
    private array $matched = [];

    /**
     * @param array<string, true> $normalizedMiddlewareFqcn
     */
    public function __construct(array $normalizedMiddlewareFqcn)
    {
        $this->targets = $normalizedMiddlewareFqcn;
    }

    /**
     * @return array<string, true>
     */
    public function getMatchedMiddleware(): array
    {
        return $this->matched;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof String_) {
            $this->matchString($node->value);

            return null;
        }

        if ($node instanceof ClassConstFetch) {
            $this->matchClassExpr($node->class);

            return null;
        }

        if ($node instanceof StaticCall) {
            $this->matchClassExpr($node->class);
            $this->matchArgsForMiddleware($node->args);
        }

        if ($node instanceof MethodCall) {
            $this->matchArgsForMiddleware($node->args);
        }

        if ($node instanceof New_) {
            $this->matchClassExpr($node->class);
        }

        if ($node instanceof Class_) {
            foreach ($node->attrGroups as $group) {
                $this->walkAttrGroup($group);
            }
        }

        if ($node instanceof ClassMethod) {
            foreach ($node->attrGroups as $group) {
                $this->walkAttrGroup($group);
            }
        }

        return null;
    }

    private function walkAttrGroup(AttributeGroup $group): void
    {
        foreach ($group->attrs as $attr) {
            $this->walkAttribute($attr);
        }
    }

    private function walkAttribute(Attribute $attr): void
    {
        $n = $attr->name;
        if ($n instanceof FullyQualified) {
            $this->maybeMatchNorm($this->normalize($n->toString()));
        } elseif ($n instanceof Name) {
            $this->maybeMatchNorm($this->normalize($n->toString()));
        }

        foreach ($attr->args as $arg) {
            $v = $arg->value ?? null;
            if ($v instanceof String_) {
                $this->matchString($v->value);
            }
            if ($v instanceof ClassConstFetch) {
                $this->matchClassExpr($v->class);
            }
        }
    }

    /**
     * @param Node\Arg[] $args
     */
    private function matchArgsForMiddleware(array $args): void
    {
        foreach ($args as $arg) {
            $v = $arg->value ?? null;
            if ($v instanceof String_) {
                $this->matchString($v->value);
            }
            if ($v instanceof ClassConstFetch) {
                $this->matchClassExpr($v->class);
            }
            if ($v instanceof StaticCall) {
                $this->matchClassExpr($v->class);
            }
        }
    }

    private function matchClassExpr(?Node $expr): void
    {
        if ($expr instanceof FullyQualified) {
            $this->maybeMatchNorm($this->normalize($expr->toString()));
        } elseif ($expr instanceof Name) {
            $this->maybeMatchNorm($this->normalize($expr->toString()));
        }
    }

    private function matchString(string $value): void
    {
        $n = $this->normalize(trim($value));
        if (isset($this->targets[$n])) {
            $this->matched[$n] = true;
        }
        foreach (array_keys($this->targets) as $t) {
            if ($t === $n || str_ends_with($t, '\\' . $n)) {
                $this->matched[$t] = true;
            }
        }
    }

    private function maybeMatchNorm(string $norm): void
    {
        if (isset($this->targets[$norm])) {
            $this->matched[$norm] = true;
        }
    }

    private function normalize(string $s): string
    {
        return strtolower(ltrim($s, '\\'));
    }
}
