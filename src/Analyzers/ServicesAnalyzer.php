<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\UnionType;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use SplFileInfo;
use Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Arafa\DeadcodeDetector\Support\AstParserFactory;
use Arafa\DeadcodeDetector\Support\ClassKindClassifier;
use Arafa\DeadcodeDetector\Support\ClassKindContext;
use Arafa\DeadcodeDetector\Support\ContainerBoundConcreteIndex;
use Arafa\DeadcodeDetector\Support\ExtendsImplementsAndTraitsIndex;
use Arafa\DeadcodeDetector\Support\PhpClassAstHelper;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;
use Arafa\DeadcodeDetector\Support\PhpFilesUnderScanPaths;
use Arafa\DeadcodeDetector\Support\PathExcludeMatcher;
use PhpParser\Node\IntersectionType;
use Arafa\DeadcodeDetector\Support\ProjectPhpIterator;

class ServicesAnalyzer implements AnalyzerInterface
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
        return function_exists('app_path') ? [app_path('Services')] : [];
    }

    public function getName(): string
    {
        return 'services';
    }

    public function getDescription(): string
    {
        return 'Finds service classes never constructed, resolved from the container, bound, or referenced (e.g. static calls).';
    }

    public function analyze(): array
    {
        $ctx = new ClassKindContext(ContainerBoundConcreteIndex::getOrBuild(
            $this->scanner,
            $this->scanPaths,
            $this->pathExclude,
        ));

        /** @var array<string, array{file: SplFileInfo, fqcn: string}> $map */
        $map = [];
        foreach (PhpFilesUnderScanPaths::eachUniqueRealPath($this->scanner, $this->scanPaths, $this->pathExclude) as $path) {
            $kinds = PhpClassAstHelper::classifyFile($path, $ctx);
            if ($kinds === null || ! ClassKindClassifier::isServiceLikeCandidate($kinds, $path)) {
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
        $v       = new ServiceUsageVisitor($targets);
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

        $this->collectBindingsConcrete($v, $targets);

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
                'type'           => 'service',
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
     */
    private function collectBindingsConcrete(ServiceUsageVisitor $v, array $targets): void
    {
        foreach ($this->scanPaths as $base) {
            $prov = rtrim((string) $base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Providers';
            if (! is_dir($prov)) {
                continue;
            }
            foreach ($this->scanner->scanDirectoryLazy($prov) as $file) {
                $path = $file->getRealPath();
                if ($path === false) {
                    continue;
                }
                $stmts = $this->parse($path);
                if ($stmts === null) {
                    continue;
                }
                $bv = new ServiceBindingConcreteVisitor($targets, $v);
                $tr = new NodeTraverser();
                $tr->addVisitor(new NameResolver());
                $tr->addVisitor($bv);
                $tr->traverse($stmts);
            }
        }
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

final class ServiceUsageVisitor extends NodeVisitorAbstract
{
    /** @var array<string, true> */
    private array $matched = [];

    /**
     * @param array<string, true> $serviceNorms
     */
    public function __construct(
        private readonly array $serviceNorms,
    ) {}

    public function mark(string $norm): void
    {
        if (isset($this->serviceNorms[$norm])) {
            $this->matched[$norm] = true;
        }
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
        if ($node instanceof ClassMethod) {
            foreach ($node->params as $p) {
                $this->paramType($p);
            }

            return null;
        }

        if ($node instanceof New_) {
            $this->markClass($node->class);

            return null;
        }

        if ($node instanceof StaticCall && ! $this->shouldSkipStaticClassExpr($node->class)) {
            $this->markClassFx($node->class);

            return null;
        }

        if ($node instanceof ClassConstFetch && $node->name instanceof Identifier && strcasecmp($node->name->name, 'class') === 0
            && ! $this->shouldSkipStaticClassExpr($node->class)) {
            $this->markClassFx($node->class);

            return null;
        }

        if ($node instanceof FuncCall) {
            $n = $node->name;
            if ($n instanceof Name && $n->getLast() === 'app' && isset($node->args[0])) {
                $a = $node->args[0]->value ?? null;
                if ($a instanceof ClassConstFetch) {
                    $this->markClassFx($a->class);
                }
            }
        }

        return null;
    }

    private function paramType(Param $param): void
    {
        $t = $param->type;
        if ($t === null) {
            return;
        }
        $this->walkType($t);
    }

    private function walkType(Node $t): void
    {
        if ($t instanceof NullableType) {
            $this->walkType($t->type);

            return;
        }
        if ($t instanceof UnionType || $t instanceof IntersectionType) {
            foreach ($t->types as $x) {
                $this->walkType($x);
            }

            return;
        }
        if ($t instanceof FullyQualified) {
            $this->mark(strtolower($t->toString()));
        } elseif ($t instanceof Name) {
            $this->mark(strtolower($t->toString()));
        }
    }

    private function markClass(?Node $c): void
    {
        $this->markClassFx($c);
    }

    private function markClassFx(?Node $class): void
    {
        if ($class instanceof FullyQualified) {
            $this->mark(strtolower(ltrim($class->toString(), '\\')));
        } elseif ($class instanceof Name) {
            $this->mark(strtolower(ltrim($class->toString(), '\\')));
        }
    }

    /**
     * Skip self:: / static:: / parent:: — not a concrete class reference.
     */
    private function shouldSkipStaticClassExpr(?Node $class): bool
    {
        if (! $class instanceof Name) {
            return false;
        }

        return in_array(strtolower($class->getLast()), ['self', 'static', 'parent'], true);
    }
}

final class ServiceBindingConcreteVisitor extends NodeVisitorAbstract
{
    /**
     * @param array<string, true>     $targets
     */
    public function __construct(
        private readonly array $targets,
        private readonly ServiceUsageVisitor $usage,
    ) {}

    public function enterNode(Node $node): ?int
    {
        if (! $node instanceof MethodCall || ! $node->name instanceof Identifier) {
            return null;
        }
        $m = $node->name->name;
        if (! in_array($m, ['bind', 'singleton', 'scoped', 'instance'], true)) {
            return null;
        }
        $a1 = $node->args[1]->value ?? null;
        if (! $a1 instanceof ClassConstFetch) {
            return null;
        }
        $c = $a1->class;
        if ($c instanceof FullyQualified) {
            $n = strtolower($c->toString());
        } elseif ($c instanceof Name) {
            $n = strtolower($c->toString());
        } else {
            return null;
        }
        if (isset($this->targets[$n])) {
            $this->usage->mark($n);
        }

        return null;
    }
}
