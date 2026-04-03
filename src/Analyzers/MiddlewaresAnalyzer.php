<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use SplFileInfo;
use Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Arafa\DeadcodeDetector\Support\AstParserFactory;
use Arafa\DeadcodeDetector\Support\ClassKindClassifier;
use Arafa\DeadcodeDetector\Support\ExtendsImplementsAndTraitsIndex;
use Arafa\DeadcodeDetector\Support\PhpClassAstHelper;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;
use Arafa\DeadcodeDetector\Support\PhpFilesUnderScanPaths;
use Arafa\DeadcodeDetector\Support\PathExcludeMatcher;

class MiddlewaresAnalyzer implements AnalyzerInterface
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

        return [app_path('Http/Middleware')];
    }

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
        /** @var array<string, array{file: SplFileInfo, fqcn: string}> */
        $metaByNorm = [];

        foreach (PhpFilesUnderScanPaths::eachUniqueRealPath($this->scanner, $this->scanPaths, $this->pathExclude) as $path) {
            $kinds = PhpClassAstHelper::classifyFile($path);
            if ($kinds === null || ! ClassKindClassifier::hasKind($kinds, ClassKindClassifier::KIND_MIDDLEWARE)) {
                continue;
            }
            $fqcn = PhpClassAstHelper::fqcnFromFile($path);
            if ($fqcn === null) {
                continue;
            }
            $metaByNorm[$this->normalizeFqcn($fqcn)] = ['file' => new SplFileInfo($path), 'fqcn' => $fqcn];
        }

        if ($metaByNorm === []) {
            return [];
        }

        $referenced = $this->collectReferencedMiddlewareKeys(array_keys($metaByNorm));

        $hierarchyTargets = ExtendsImplementsAndTraitsIndex::build(
            $this->scanner,
            $this->scanPaths,
            $this->pathExclude,
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
            foreach ($this->scanner->scanDirectoryLazy($routesDir) as $file) {
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

            foreach ($this->scanner->scanDirectoryLazy($ctrlDir) as $file) {
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

    private function normalizeFqcn(string $fqcn): string
    {
        return strtolower(ltrim($fqcn, '\\'));
    }

    private function isExcluded(string $path): bool
    {
        return $this->pathExclude->shouldExclude($path);
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
            $this->scanExprForMiddleware($arg->value ?? null);
        }
    }

    private function scanExprForMiddleware(?Node $v): void
    {
        if ($v === null) {
            return;
        }

        if ($v instanceof String_) {
            $this->matchString($v->value);

            return;
        }

        if ($v instanceof ClassConstFetch) {
            $this->matchClassExpr($v->class);

            return;
        }

        if ($v instanceof StaticCall) {
            $this->matchClassExpr($v->class);
            $this->matchArgsForMiddleware($v->args);

            return;
        }

        if ($v instanceof MethodCall) {
            $this->matchArgsForMiddleware($v->args);

            return;
        }

        if ($v instanceof New_) {
            $this->matchClassExpr($v->class);

            return;
        }

        if ($v instanceof Array_) {
            foreach ($v->items as $item) {
                if ($item === null) {
                    continue;
                }
                $this->scanExprForMiddleware($item->value ?? null);
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
