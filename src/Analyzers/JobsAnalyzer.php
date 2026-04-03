<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use SplFileInfo;
use Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Arafa\DeadcodeDetector\Support\CachedAstParser;
use Arafa\DeadcodeDetector\Support\ClassKindClassifier;
use Arafa\DeadcodeDetector\Support\DependencyGraphEngine;
use Arafa\DeadcodeDetector\Support\ExtendsImplementsAndTraitsIndex;
use Arafa\DeadcodeDetector\Support\PhpClassAstHelper;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;
use Arafa\DeadcodeDetector\Support\PhpFilesUnderScanPaths;
use Arafa\DeadcodeDetector\Support\PathExcludeMatcher;
use Arafa\DeadcodeDetector\Support\ProjectPhpIterator;

class JobsAnalyzer implements AnalyzerInterface
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
        return function_exists('app_path') ? [app_path('Jobs')] : [];
    }

    public function getName(): string
    {
        return 'jobs';
    }

    public function getDescription(): string
    {
        return 'Finds queueable job classes (AST: ShouldQueue / extends Job) under scan paths that are never dispatched.';
    }

    public function analyze(): array
    {
        /** @var array<string, array{file: SplFileInfo, fqcn: string, norm: string}> $jobs */
        $jobs = [];

        foreach (PhpFilesUnderScanPaths::eachUniqueRealPath($this->scanner, $this->scanPaths, $this->pathExclude) as $path) {
            $kinds = PhpClassAstHelper::classifyFile($path);
            if ($kinds === null || ! ClassKindClassifier::hasKind($kinds, ClassKindClassifier::KIND_JOB)) {
                continue;
            }
            $fqcn = PhpClassAstHelper::fqcnFromFile($path);
            if ($fqcn === null) {
                continue;
            }
            $norm                                     = $this->norm($fqcn);
            $jobs[$norm] = [
                'file' => new SplFileInfo($path),
                'fqcn' => $fqcn,
                'norm' => $norm,
            ];
        }

        if ($jobs === []) {
            return [];
        }

        $dispatched = $this->collectDispatchedJobNorms(array_keys($jobs));
        $hierarchy  = ExtendsImplementsAndTraitsIndex::build(
            $this->scanner,
            $this->scanPaths,
            $this->pathExclude,
        );
        $graph = DependencyGraphEngine::getOrBuild($this->scanner, $this->scanPaths, $this->pathExclude);

        $results = [];
        foreach ($jobs as $norm => $meta) {
            if (isset($dispatched[$norm]) || isset($hierarchy[$norm])) {
                continue;
            }
            $f = $meta['file'];
            $p = $f->getRealPath();
            if ($p === false) {
                continue;
            }

            if ($graph->isClassReferencedFromOtherPhpFiles($norm, $p)) {
                continue;
            }

            $results[] = DeadCodeResult::fromArray([
                'analyzerName'    => $this->getName(),
                'type'            => 'job',
                'filePath'        => $p,
                'className'       => $meta['fqcn'],
                'methodName'      => null,
                'lastModified'    => date('Y-m-d H:i:s', $f->getMTime()),
                'isSafeToDelete'  => false,
                'orphanedHint'    => true,
                'confidenceLevel' => 'medium',
                'reason'          => 'No dispatch()/Bus::dispatch usage found for this job in scanned code, it is not extended as a base type elsewhere, and no other file references this class via new/static/::class/instanceof in the dependency graph.',
            ]);
        }

        return $results;
    }

    /**
     * @param list<string> $jobNorms
     *
     * @return array<string, true>
     */
    private function collectDispatchedJobNorms(array $jobNorms): array
    {
        $target = [];
        foreach ($jobNorms as $n) {
            $target[$n] = true;
        }

        $collector = new JobDispatchCollector($target);
        foreach ($this->iteratePhpSources() as $path) {
            $stmts = $this->parseStatements($path);
            if ($stmts === null) {
                continue;
            }
            $tr = new NodeTraverser();
            $tr->addVisitor(new NameResolver());
            $tr->addVisitor($collector);
            $tr->traverse($stmts);
        }

        return $collector->getMatched();
    }

    /**
     * @return \Generator<string>
     */
    private function iteratePhpSources(): \Generator
    {
        yield from ProjectPhpIterator::iterate($this->scanner, $this->scanPaths, $this->pathExclude);
    }

    private function parseStatements(string $path): ?array
    {
        return CachedAstParser::parseFile($path);
    }

    private function norm(string $fqcn): string
    {
        return strtolower(ltrim($fqcn, '\\'));
    }

}

final class JobDispatchCollector extends NodeVisitorAbstract
{
    /** @var array<string, true> */
    private array $targets;

    /** @var array<string, true> */
    private array $matched = [];

    /**
     * @param array<string, true> $normalizedJobFqcnKeys
     */
    public function __construct(array $normalizedJobFqcnKeys)
    {
        $this->targets = $normalizedJobFqcnKeys;
    }

    /**
     * @return array<string, true>
     */
    public function getMatched(): array
    {
        return $this->matched;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof StaticCall && $node->name instanceof Identifier) {
            $m = strtolower($node->name->name);
            if (in_array($m, ['dispatch', 'dispatchif', 'dispatchunless', 'dispatchafterresponse', 'dispatchsync'], true)) {
                $this->tryMarkClassFx($node->class);
            }
            if ($m === 'dispatch' && $this->isBusFacade($node->class)) {
                foreach ($node->args as $arg) {
                    $v = $arg->value ?? null;
                    if ($v instanceof ClassConstFetch) {
                        $this->tryMarkClassFx($v->class);
                    }
                    if ($v instanceof New_) {
                        $this->tryMarkClassFx($v->class);
                    }
                }
            }

            return null;
        }

        if ($node instanceof MethodCall && $node->name instanceof Identifier && strtolower($node->name->name) === 'dispatch') {
            foreach ($node->args as $arg) {
                $v = $arg->value ?? null;
                if ($v instanceof ClassConstFetch) {
                    $this->tryMarkClassFx($v->class);
                }
                if ($v instanceof New_) {
                    $this->tryMarkClassFx($v->class);
                }
            }
        }

        if ($node instanceof FuncCall) {
            $fn = $this->funcName($node);
            if ($fn === 'dispatch' && isset($node->args[0])) {
                $v = $node->args[0]->value ?? null;
                if ($v instanceof ClassConstFetch) {
                    $this->tryMarkClassFx($v->class);
                }
                if ($v instanceof New_) {
                    $this->tryMarkClassFx($v->class);
                }
            }
        }

        return null;
    }

    private function isBusFacade(?Node $class): bool
    {
        if ($class instanceof Name) {
            return $class->getLast() === 'Bus';
        }
        if ($class instanceof FullyQualified) {
            return str_ends_with($class->toString(), '\\Facades\\Bus');
        }

        return false;
    }

    private function tryMarkClassFx(?Node $class): void
    {
        if ($class instanceof FullyQualified) {
            $n = strtolower($class->toString());
        } elseif ($class instanceof Name) {
            $n = strtolower($class->toString());
        } else {
            return;
        }

        if (isset($this->targets[$n])) {
            $this->matched[$n] = true;
        }
    }

    private function funcName(FuncCall $node): ?string
    {
        $n = $node->name;
        if ($n instanceof Name) {
            return $n->getLast();
        }
        if ($n instanceof FullyQualified) {
            return $n->getLast();
        }

        return null;
    }
}
