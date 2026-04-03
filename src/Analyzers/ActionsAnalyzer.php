<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
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
use Arafa\DeadcodeDetector\Support\ProjectPhpIterator;

class ActionsAnalyzer implements AnalyzerInterface
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
        return function_exists('app_path') ? [app_path('Actions')] : [];
    }

    public function getName(): string
    {
        return 'actions';
    }

    public function getDescription(): string
    {
        return 'Finds action-style classes (*Action / app/Actions) that are never executed.';
    }

    public function analyze(): array
    {
        /** @var array<string, array{file: SplFileInfo, fqcn: string}> $map */
        $map = [];
        foreach (PhpFilesUnderScanPaths::eachUniqueRealPath($this->scanner, $this->scanPaths, $this->pathExclude) as $path) {
            $kinds = PhpClassAstHelper::classifyFile($path);
            if ($kinds === null || ! ClassKindClassifier::isActionCandidate($kinds, $path)) {
                continue;
            }
            $fqcn = PhpClassAstHelper::fqcnFromFile($path);
            if ($fqcn === null) {
                continue;
            }
            $map[strtolower(ltrim($fqcn, '\\'))] = ['file' => new SplFileInfo($path), 'fqcn' => $fqcn];
        }

        if ($map === []) {
            return [];
        }

        $targets = array_fill_keys(array_keys($map), true);
        $v       = new ActionClassUsageVisitor($targets);
        foreach (ProjectPhpIterator::iterate($this->scanner, $this->scanPaths, $this->pathExclude) as $path) {
            $stmts = $this->parse($path);
            if ($stmts === null) {
                continue;
            }
            $tr = new NodeTraverser();
            $tr->addVisitor(new NameResolver());
            $tr->addVisitor($v);
            $tr->traverse($stmts);
        }

        $used = $v->getMatched();
        $hier = ExtendsImplementsAndTraitsIndex::build($this->scanner, $this->scanPaths, $this->pathExclude);

        $results = [];
        foreach ($map as $norm => $meta) {
            if (isset($used[$norm]) || isset($hier[$norm])) {
                continue;
            }
            $p = $meta['file']->getRealPath();
            if ($p === false) {
                continue;
            }
            $results[] = DeadCodeResult::fromArray([
                'analyzerName'   => $this->getName(),
                'type'           => 'action',
                'filePath'       => $p,
                'className'      => $meta['fqcn'],
                'methodName'     => null,
                'lastModified'   => date('Y-m-d H:i:s', $meta['file']->getMTime()),
                'isSafeToDelete' => false,
            ]);
        }

        return $results;
    }

    private function extractFqcn(string $path): ?string
    {
        $stmts = $this->parse($path);
        if ($stmts === null) {
            return null;
        }
        $ns = null;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_ && $stmt->name !== null) {
                $ns = $stmt->name->toString();
                foreach ($stmt->stmts ?? [] as $inner) {
                    if ($inner instanceof Class_ && $inner->name !== null) {
                        $c = $inner->name->name;

                        return ($ns !== '' && $ns !== null) ? $ns . '\\' . $c : $c;
                    }
                }
            } elseif ($stmt instanceof Class_ && $stmt->name !== null) {
                return $stmt->name->name;
            }
        }

        return null;
    }

    private function parse(string $path): ?array
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
}

final class ActionClassUsageVisitor extends NodeVisitorAbstract
{
    /** @var array<string, true> */
    private array $matched = [];

    /**
     * @param array<string, true> $targets
     */
    public function __construct(
        private readonly array $targets,
    ) {}

    /**
     * @return array<string, true>
     */
    public function getMatched(): array
    {
        return $this->matched;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof New_) {
            $this->markClass($node->class);

            return null;
        }

        if ($node instanceof StaticCall && $node->name instanceof Identifier) {
            $m = strtolower($node->name->name);
            if (in_array($m, ['run', 'execute', 'handle'], true)) {
                $this->markClass($node->class);
            }
        }

        if ($node instanceof FuncCall) {
            $fn = $this->funcName($node);
            if ($fn === 'app' && isset($node->args[0])) {
                $a = $node->args[0]->value ?? null;
                if ($a instanceof ClassConstFetch) {
                    $this->markFx($a->class);
                }
            }
        }

        return null;
    }

    private function markClass(?Node $class): void
    {
        $this->markFx($class);
    }

    private function markFx(?Node $class): void
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
