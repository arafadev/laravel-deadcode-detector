<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\NodeTraverser;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\UnionType;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use SplFileInfo;
use Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Arafa\DeadcodeDetector\Support\AstParserFactory;
use Arafa\DeadcodeDetector\Support\ExtendsImplementsAndTraitsIndex;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;

class ModelsAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly PhpFileScanner $scanner,
        private readonly array $scanPaths,
        private readonly array $excludePaths = [],
    ) {}

    public function getName(): string
    {
        return 'models';
    }

    public function getDescription(): string
    {
        return 'Finds unused Eloquent models and unused local query scopes (scope* methods).';
    }

    public function analyze(): array
    {
        $modelFiles = $this->findModelFiles();

        if ($modelFiles === []) {
            return [];
        }

        /** @var array<string, array{file: SplFileInfo, fqcn: string}> */
        $modelMetaByNormalized = [];

        foreach ($modelFiles as $file) {
            $fqcn = $this->extractClassNameFromFile($file);
            if ($fqcn === null) {
                continue;
            }
            $normalized = $this->normalizeFqcn($fqcn);
            $modelMetaByNormalized[$normalized] = ['file' => $file, 'fqcn' => $fqcn];
        }

        $referenced = $this->collectReferencedModelsFromNonModelFiles(
            array_keys($modelMetaByNormalized),
            array_values(array_map(fn ($f) => $f->getRealPath(), $modelFiles))
        );

        $hierarchyTargets = ExtendsImplementsAndTraitsIndex::build(
            $this->scanner,
            $this->scanPaths,
            $this->excludePaths,
        );

        $results = [];

        $modelPaths = [];
        foreach ($modelMetaByNormalized as $meta) {
            $rp = $meta['file']->getRealPath();
            if ($rp !== false) {
                $modelPaths[$rp] = true;
            }
        }

        foreach ($modelMetaByNormalized as $normalized => $meta) {
            if (isset($referenced[$normalized]) || isset($hierarchyTargets[$normalized])) {
                continue;
            }

            $file = $meta['file'];

            $results[] = DeadCodeResult::fromArray([
                'analyzerName'   => $this->getName(),
                'type'           => 'model',
                'filePath'       => $file->getRealPath(),
                'className'      => $meta['fqcn'],
                'methodName'     => null,
                'lastModified'   => date('Y-m-d H:i:s', $file->getMTime()),
                'isSafeToDelete' => false,
            ]);
        }

        foreach ($modelMetaByNormalized as $normalized => $meta) {
            if (! isset($referenced[$normalized])) {
                continue;
            }

            $scopes = $this->extractScopeMethodNames($meta['file']);
            if ($scopes === []) {
                continue;
            }

            $fqcn = $meta['fqcn'];
            foreach ($scopes as $scopeCamel) {
                if ($this->isScopeUsedElsewhere($fqcn, $scopeCamel, $modelPaths)) {
                    continue;
                }

                $results[] = DeadCodeResult::fromArray([
                    'analyzerName'   => $this->getName(),
                    'type'           => 'model_scope',
                    'filePath'       => $meta['file']->getRealPath(),
                    'className'      => $fqcn,
                    'methodName'     => 'scope' . ucfirst($scopeCamel),
                    'lastModified'   => date('Y-m-d H:i:s', $meta['file']->getMTime()),
                    'isSafeToDelete' => false,
                ]);
            }
        }

        return $results;
    }

    /**
     * @param  string[] $modelFqcnList
     * @param  string[] $modelRealPaths
     * @return array<string, true> normalized FQCN => true
     */
    private function collectReferencedModelsFromNonModelFiles(array $modelFqcnList, array $modelRealPaths): array
    {
        $modelSet = [];
        foreach ($modelFqcnList as $fq) {
            $modelSet[$this->normalizeFqcn($fq)] = true;
        }

        $modelPathLookup = [];
        foreach ($modelRealPaths as $p) {
            $modelPathLookup[$p] = true;
        }

        /** @var array<string, true> */
        $referenced = [];

        foreach ($this->iteratePhpFilesOutsideModels($modelPathLookup) as $path) {
            $stmts = $this->parseStatements($path);
            if ($stmts === null) {
                continue;
            }

            $visitor = new ModelReferenceVisitor($modelSet);
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($visitor);
            $traverser->traverse($stmts);

            foreach ($visitor->getReferencedNormalizedFqcn() as $n => $_) {
                $referenced[$n] = true;
            }
        }

        return $referenced;
    }

    /**
     * @param  array<string, true> $modelPathLookup
     * @return \Generator<string>
     */
    private function iteratePhpFilesOutsideModels(array $modelPathLookup): \Generator
    {
        foreach ($this->scanPaths as $basePath) {
            foreach ($this->scanner->scanDirectory($basePath) as $file) {
                $real = $file->getRealPath();
                if ($real === false || isset($modelPathLookup[$real]) || $this->isExcluded($real)) {
                    continue;
                }
                yield $real;
            }
        }

        foreach (['routes', 'config', 'database'] as $dir) {
            $dirPath = base_path($dir);
            if (! is_dir($dirPath)) {
                continue;
            }
            foreach ($this->scanner->scanDirectory($dirPath) as $file) {
                $real = $file->getRealPath();
                if ($real === false || isset($modelPathLookup[$real]) || $this->isExcluded($real)) {
                    continue;
                }
                yield $real;
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
    private function findModelFiles(): array
    {
        $found = [];

        foreach ($this->scanPaths as $basePath) {
            $modelDirs = [
                rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Models',
                rtrim($basePath, DIRECTORY_SEPARATOR),
            ];

            foreach ($modelDirs as $dir) {
                if (! is_dir($dir)) {
                    continue;
                }

                foreach ($this->scanner->scanDirectory($dir) as $file) {
                    $real = $file->getRealPath();
                    if ($real === false || $this->isExcluded($real)) {
                        continue;
                    }

                    if ($this->isEloquentModelFile($file)) {
                        $found[$real] = $file;
                    }
                }
            }
        }

        return array_values($found);
    }

    private function isEloquentModelFile(SplFileInfo $file): bool
    {
        $path = $file->getRealPath();
        if ($path === false) {
            return false;
        }

        $stmts = $this->parseStatements($path);
        if ($stmts === null) {
            return false;
        }

        $visitor = new EloquentModelDetectionVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return $visitor->isEloquentModel();
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

    /**
     * @return list<string> Camel scope name without "scope" prefix (e.g. published for scopePublished)
     */
    private function extractScopeMethodNames(SplFileInfo $file): array
    {
        $path = $file->getRealPath();
        if ($path === false) {
            return [];
        }

        $stmts = $this->parseStatements($path);
        if ($stmts === null) {
            return [];
        }

        $visitor = new ModelScopeMethodsVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return $visitor->getScopeCamelNames();
    }

    /**
     * @param array<string, true> $modelPaths
     */
    private function isScopeUsedElsewhere(string $modelFqcn, string $scopeCamel, array $modelPaths): bool
    {
        $targetNorm = $this->normalizeFqcn($modelFqcn);

        foreach ($this->iteratePhpFilesForScopeSearch($modelPaths) as $path) {
            $stmts = $this->parseStatements($path);
            if ($stmts === null) {
                continue;
            }

            $visitor = new ModelScopeStaticCallVisitor($targetNorm, $scopeCamel);
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($visitor);
            $traverser->traverse($stmts);

            if ($visitor->isFound()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, true> $modelPaths
     *
     * @return \Generator<string>
     */
    private function iteratePhpFilesForScopeSearch(array $modelPaths): \Generator
    {
        foreach ($this->scanPaths as $basePath) {
            foreach ($this->scanner->scanDirectory($basePath) as $file) {
                $real = $file->getRealPath();
                if ($real === false || isset($modelPaths[$real]) || $this->isExcluded($real)) {
                    continue;
                }
                yield $real;
            }
        }

        foreach (['routes', 'config', 'database'] as $dir) {
            $dirPath = base_path($dir);
            if (! is_dir($dirPath)) {
                continue;
            }
            foreach ($this->scanner->scanDirectory($dirPath) as $file) {
                $real = $file->getRealPath();
                if ($real === false || isset($modelPaths[$real]) || $this->isExcluded($real)) {
                    continue;
                }
                yield $real;
            }
        }
    }
}

/** Collects scope* method camel names from an Eloquent model class. */
final class ModelScopeMethodsVisitor extends NodeVisitorAbstract
{
    /** @var list<string> */
    private array $scopes = [];

    public function enterNode(Node $node): ?int
    {
        if (! $node instanceof ClassMethod || ! $node->isPublic()) {
            return null;
        }

        $name = $node->name->name;
        if (! str_starts_with($name, 'scope') || strlen($name) <= 5) {
            return null;
        }

        $rest = substr($name, 5);
        if ($rest === '') {
            return null;
        }

        $this->scopes[] = lcfirst($rest);

        return null;
    }

    /**
     * @return list<string>
     */
    public function getScopeCamelNames(): array
    {
        return array_values(array_unique($this->scopes));
    }
}

/** Detects Model::scopeName() static calls after NameResolver. */
final class ModelScopeStaticCallVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private readonly string $modelFqcnNormalized,
        private readonly string $scopeCamel,
    ) {}

    private bool $found = false;

    public function isFound(): bool
    {
        return $this->found;
    }

    public function enterNode(Node $node): ?int
    {
        if (! $node instanceof StaticCall) {
            return null;
        }

        $class = $node->class;
        $fqcn  = null;
        if ($class instanceof FullyQualified) {
            $fqcn = strtolower(ltrim($class->toString(), '\\'));
        } elseif ($class instanceof Name) {
            $fqcn = strtolower(ltrim($class->toString(), '\\'));
        }

        if ($fqcn !== $this->modelFqcnNormalized) {
            return null;
        }

        if (! $node->name instanceof Identifier) {
            return null;
        }

        if (strcasecmp($node->name->name, $this->scopeCamel) === 0) {
            $this->found = true;

            return NodeVisitor::STOP_TRAVERSAL;
        }

        return null;
    }
}

/** Detects classes that extend Eloquent Model / Authenticatable / Pivot. */
final class EloquentModelDetectionVisitor extends NodeVisitorAbstract
{
    private bool $match = false;

    public function enterNode(Node $node): ?int
    {
        if (! $node instanceof Class_ || $node->extends === null) {
            return null;
        }

        $parent = $node->extends;
        if ($parent instanceof Name) {
            $name = $parent->getLast();
        } elseif ($parent instanceof FullyQualified) {
            $name = $parent->getLast();
        } else {
            return null;
        }

        $full = $parent instanceof FullyQualified ? $parent->toString() : $name;

        if (in_array($name, ['Model', 'Authenticatable', 'Pivot'], true)) {
            $this->match = true;

            return null;
        }

        if (
            str_ends_with($full, '\\Model')
            || str_ends_with($full, '\\Authenticatable')
            || str_ends_with($full, '\\Pivot')
        ) {
            $this->match = true;
        }

        return null;
    }

    public function isEloquentModel(): bool
    {
        return $this->match;
    }
}

/** Records references to known model classes from types, calls, and PHPDoc (docblock regex only). */
final class ModelReferenceVisitor extends NodeVisitorAbstract
{
    /**
     * @param array<string, true> $modelFqcnNormalized keys are strtolower(ltrim(fqcn))
     */
    public function __construct(
        private readonly array $modelFqcnNormalizedSet,
    ) {}

    /** @var array<string, true> */
    private array $referenced = [];

    /**
     * @return array<string, true>
     */
    public function getReferencedNormalizedFqcn(): array
    {
        return $this->referenced;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof ClassConstFetch) {
            $this->maybeAddClassExpr($node->class);
        } elseif ($node instanceof StaticCall) {
            $this->maybeAddClassExpr($node->class);
        } elseif ($node instanceof New_) {
            $this->maybeAddClassExpr($node->class);
        } elseif ($node instanceof Instanceof_) {
            $this->maybeAddClassExpr($node->class);
        } elseif ($node instanceof Param && $node->type !== null) {
            $this->addFromComplexType($node->type);
        } elseif ($node instanceof ClassMethod && $node->returnType !== null) {
            $this->addFromComplexType($node->returnType);
        } elseif ($node instanceof Function_ && $node->returnType !== null) {
            $this->addFromComplexType($node->returnType);
        } elseif ($node instanceof Closure) {
            if ($node->returnType !== null) {
                $this->addFromComplexType($node->returnType);
            }
            foreach ($node->params as $p) {
                if ($p->type !== null) {
                    $this->addFromComplexType($p->type);
                }
            }
        } elseif ($node instanceof Property && $node->type !== null) {
            $this->addFromComplexType($node->type);
        }

        $doc = $node->getDocComment();
        if ($doc !== null) {
            $this->parsePhpDocForModels($doc->getText());
        }

        return null;
    }

    private function maybeAddClassExpr(?Node $expr): void
    {
        if ($expr instanceof FullyQualified) {
            $this->maybeAddNormalized($expr->toString());
        } elseif ($expr instanceof Name) {
            $this->maybeAddNormalized($expr->toString());
        }
    }

    private function addFromComplexType(Node $type): void
    {
        if ($type instanceof Identifier) {
            $this->maybeAddNormalized($type->name);

            return;
        }

        if ($type instanceof Name || $type instanceof FullyQualified) {
            $this->maybeAddNormalized($type->toString());

            return;
        }

        if ($type instanceof NullableType) {
            $this->addFromComplexType($type->type);

            return;
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $t) {
                $this->addFromComplexType($t);
            }
        }
    }

    private function maybeAddNormalized(string $nameOrFqcn): void
    {
        $n = $this->normalizeFqcn($nameOrFqcn);
        if (isset($this->modelFqcnNormalizedSet[$n])) {
            $this->referenced[$n] = true;
        }
    }

    private function normalizeFqcn(string $fqcn): string
    {
        return strtolower(ltrim($fqcn, '\\'));
    }

    /**
     * Regex applies only to raw docblock text provided by the AST.
     */
    private function parsePhpDocForModels(string $docText): void
    {
        if (
            ! preg_match_all(
                '/@(?:param|return|var|property(?:-read|-write)?)\s+([^\s*]+)/i',
                $docText,
                $matches
            )
        ) {
            return;
        }

        foreach ($matches[1] as $typeChunk) {
            $parts = preg_split('/[|&<>\s,]+/', $typeChunk) ?: [];
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '' || str_starts_with($p, '$')) {
                    continue;
                }
                $p = ltrim($p, '\\');
                if ($p !== '' && (str_contains($p, '\\') || (strlen($p) > 0 && ctype_upper($p[0])))) {
                    $this->maybeAddNormalized($p);
                }
            }
        }
    }
}
