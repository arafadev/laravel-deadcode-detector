<?php
declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\UnionType;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use SplFileInfo;
use Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Arafa\DeadcodeDetector\Support\AnalyzerSubdirRoots;
use Arafa\DeadcodeDetector\Support\AstParserFactory;
use Arafa\DeadcodeDetector\Support\ExtendsImplementsAndTraitsIndex;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;
use Arafa\DeadcodeDetector\Support\PathExcludeMatcher;
use Arafa\DeadcodeDetector\Support\ProjectPhpIterator;
use PhpParser\Node\IntersectionType;

class EnumsAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly PhpFileScanner $scanner,
        private readonly array $scanPaths,
        private readonly PathExcludeMatcher $pathExclude,
    ) {}

    /** @return list<string> */
    public static function defaultScanPaths(): array
    {
        return function_exists('app_path') ? [app_path('Enums')] : [];
    }

    public function getName(): string { return 'enums'; }

    public function getDescription(): string
    {
        return 'Finds PHP enums that are never referenced (cases, casts, or calls).';
    }

    public function analyze(): array
    {
        $roots = AnalyzerSubdirRoots::exclusive($this->scanPaths, 'Enums');
        if ($roots === []) {
            return [];
        }
        /** @var array<string, array{file: SplFileInfo, fqcn: string}> $map */
        $map = [];
        foreach ($roots as $root) {
            foreach ($this->scanner->scanDirectoryLazy($root) as $file) {
                $path = $file->getRealPath();
                if ($path === false || $this->pathExclude->shouldExclude($path)) {
                    continue;
                }
                $fqcn = $this->extractEnumFqcn($path);
                if ($fqcn === null) {
                    continue;
                }
                $map[strtolower(ltrim($fqcn, '\\'))] = ['file' => $file, 'fqcn' => $fqcn];
            }
        }
        if ($map === []) {
            return [];
        }
        $targets = array_fill_keys(array_keys($map), true);
        $v = new EnumReferenceVisitor($targets);
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
                'analyzerName' => $this->getName(),
                'type'         => 'enum',
                'filePath'     => $p,
                'className'    => $meta['fqcn'],
                'methodName'   => null,
                'lastModified' => date('Y-m-d H:i:s', $meta['file']->getMTime()),
                'isSafeToDelete' => false,
            ]);
        }
        return $results;
    }

    private function extractEnumFqcn(string $path): ?string
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
                    if ($inner instanceof Enum_ && $inner->name !== null) {
                        $c = $inner->name->name;
                        return ($ns !== '' && $ns !== null) ? $ns . '\\' . $c : $c;
                    }
                }
            } elseif ($stmt instanceof Enum_ && $stmt->name !== null) {
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

final class EnumReferenceVisitor extends NodeVisitorAbstract
{
    /** @var array<string, true> */
    private array $matched = [];
    /** @param array<string, true> $targets */
    public function __construct(private readonly array $targets) {}
    /** @return array<string, true> */
    public function getMatched(): array { return $this->matched; }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof ClassConstFetch) {
            $this->markClass($node->class);
            return null;
        }
        if ($node instanceof StaticCall && $node->name instanceof Identifier) {
            $m = strtolower($node->name->name);
            if (in_array($m, ['from', 'tryfrom', 'cases'], true)) {
                $this->markClass($node->class);
            }
        }
        if ($node instanceof Param && $node->type !== null) {
            $this->walkType($node->type);
        }
        if ($node instanceof Property && $node->type !== null) {
            $this->walkType($node->type);
        }
        if ($node instanceof Property) {
            foreach ($node->props as $prop) {
                $def = $prop->default ?? null;
                if ($def instanceof Array_) {
                    foreach ($def->items as $item) {
                        if ($item === null) {
                            continue;
                        }
                        $v = $item->value ?? null;
                        if ($v instanceof ClassConstFetch) {
                            $this->markClass($v->class);
                        }
                    }
                }
            }
        }
        return null;
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
            $this->maybeMark(strtolower($t->toString()));
        } elseif ($t instanceof Name) {
            $this->maybeMark(strtolower($t->toString()));
        }
    }

    private function markClass(?Node $c): void
    {
        if ($c instanceof FullyQualified) {
            $this->maybeMark(strtolower($c->toString()));
        } elseif ($c instanceof Name) {
            $this->maybeMark(strtolower($c->toString()));
        }
    }

    private function maybeMark(string $n): void
    {
        if (isset($this->targets[$n])) {
            $this->matched[$n] = true;
        }
    }
}
