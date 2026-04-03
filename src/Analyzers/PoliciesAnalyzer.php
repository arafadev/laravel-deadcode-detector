<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
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

class PoliciesAnalyzer implements AnalyzerInterface
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
        return function_exists('app_path') ? [app_path('Policies')] : [];
    }

    public function getName(): string
    {
        return 'policies';
    }

    public function getDescription(): string
    {
        return 'Finds policy classes that are never registered with Gate::policy or referenced.';
    }

    public function analyze(): array
    {
        /** @var array<string, array{file: SplFileInfo, fqcn: string}> $map */
        $map = [];
        foreach (PhpFilesUnderScanPaths::eachUniqueRealPath($this->scanner, $this->scanPaths, $this->pathExclude) as $path) {
            $fqcn = PhpClassAstHelper::fqcnFromFile($path);
            if ($fqcn === null) {
                continue;
            }
            $kinds = PhpClassAstHelper::classifyFile($path);
            if ($kinds === null || ! ClassKindClassifier::isPolicyCandidate($kinds, $fqcn)) {
                continue;
            }
            $map[strtolower(ltrim($fqcn, '\\'))] = ['file' => new SplFileInfo($path), 'fqcn' => $fqcn];
        }

        if ($map === []) {
            return [];
        }

        $targets = array_fill_keys(array_keys($map), true);
        $used    = $this->collectPolicyUsage($targets);
        $hier    = ExtendsImplementsAndTraitsIndex::build($this->scanner, $this->scanPaths, $this->pathExclude);

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
                'type'           => 'policy',
                'filePath'       => $p,
                'className'      => $meta['fqcn'],
                'methodName'     => null,
                'lastModified'   => date('Y-m-d H:i:s', $meta['file']->getMTime()),
                'isSafeToDelete' => false,
            ]);
        }

        return $results;
    }

    /**
     * @param array<string, true> $targets
     *
     * @return array<string, true>
     */
    private function collectPolicyUsage(array $targets): array
    {
        $v = new PolicyUsageVisitor($targets);
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

        return $v->getMatched();
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

final class PolicyUsageVisitor extends NodeVisitorAbstract
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
        if ($node instanceof StaticCall && $node->name instanceof Identifier && strtolower($node->name->name) === 'policy') {
            if ($this->isGate($node->class) && isset($node->args[1])) {
                $this->markArgClass($node->args[1]->value ?? null);
            }

            return null;
        }

        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            $m = strtolower($node->name->name);
            if ($m === 'authorize' || $m === 'denies' || $m === 'allows' || $m === 'check') {
                foreach ($node->args as $arg) {
                    $this->markArgClass($arg->value ?? null);
                }
            }
        }

        if ($node instanceof StaticCall && $node->name instanceof Identifier) {
            $m = strtolower($node->name->name);
            if (in_array($m, ['allows', 'denies', 'check', 'authorize'], true) && $this->isGate($node->class)) {
                foreach ($node->args as $arg) {
                    $this->markArgClass($arg->value ?? null);
                }
            }
        }

        if ($node instanceof ClassConstFetch) {
            $this->markClassConst($node);
        }

        return null;
    }

    private function isGate(?Node $class): bool
    {
        if ($class instanceof Name) {
            return $class->getLast() === 'Gate';
        }
        if ($class instanceof FullyQualified) {
            return str_ends_with($class->toString(), '\\Facades\\Gate') || $class->toString() === 'Illuminate\Support\Facades\Gate';
        }

        return false;
    }

    private function markArgClass(?Node $v): void
    {
        if ($v instanceof ClassConstFetch) {
            $this->markClassConst($v);
        }
    }

    private function markClassConst(ClassConstFetch $expr): void
    {
        if (! $expr->name instanceof Identifier || strtolower($expr->name->name) !== 'class') {
            return;
        }
        $c = $expr->class;
        if ($c instanceof FullyQualified) {
            $n = strtolower($c->toString());
        } elseif ($c instanceof Name) {
            $n = strtolower($c->toString());
        } else {
            return;
        }
        if (isset($this->targets[$n])) {
            $this->matched[$n] = true;
        }
    }
}
