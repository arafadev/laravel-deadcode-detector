<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
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
use Arafa\DeadcodeDetector\Support\ExtendsImplementsAndTraitsIndex;
use Arafa\DeadcodeDetector\Support\PhpClassAstHelper;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;
use Arafa\DeadcodeDetector\Support\PhpFilesUnderScanPaths;
use Arafa\DeadcodeDetector\Support\PathExcludeMatcher;
use Arafa\DeadcodeDetector\Support\ProjectPhpIterator;

class RequestsAnalyzer implements AnalyzerInterface
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
        return function_exists('app_path') ? [app_path('Http/Requests')] : [];
    }

    public function getName(): string
    {
        return 'requests';
    }

    public function getDescription(): string
    {
        return 'Finds FormRequest classes that are never type-hinted on controller actions or route callables.';
    }

    public function analyze(): array
    {
        /** @var array<string, array{file: SplFileInfo, fqcn: string}> $map */
        $map = [];
        foreach (PhpFilesUnderScanPaths::eachUniqueRealPath($this->scanner, $this->scanPaths, $this->pathExclude) as $path) {
            $kinds = PhpClassAstHelper::classifyFile($path);
            if ($kinds === null || ! ClassKindClassifier::hasKind($kinds, ClassKindClassifier::KIND_REQUEST)) {
                continue;
            }
            $fqcn = PhpClassAstHelper::fqcnFromFile($path);
            if ($fqcn === null) {
                continue;
            }
            $map[$this->norm($fqcn)] = ['file' => new SplFileInfo($path), 'fqcn' => $fqcn];
        }

        if ($map === []) {
            return [];
        }

        $targets = [];
        foreach (array_keys($map) as $k) {
            $targets[$k] = true;
        }

        $hinted = $this->collectTypeHintedNorms($targets);
        $hier    = ExtendsImplementsAndTraitsIndex::build($this->scanner, $this->scanPaths, $this->pathExclude);

        $results = [];
        foreach ($map as $norm => $meta) {
            if (isset($hinted[$norm]) || isset($hier[$norm])) {
                continue;
            }
            $p = $meta['file']->getRealPath();
            if ($p === false) {
                continue;
            }
            $results[] = DeadCodeResult::fromArray([
                'analyzerName'   => $this->getName(),
                'type'           => 'request',
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
     * @param array<string, true> $targetNorms
     *
     * @return array<string, true>
     */
    private function collectTypeHintedNorms(array $targetNorms): array
    {
        $found = [];
        $v     = new FormRequestParamHintVisitor($targetNorms);
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

    private function norm(string $fqcn): string
    {
        return strtolower(ltrim($fqcn, '\\'));
    }

}

final class FormRequestParamHintVisitor extends NodeVisitorAbstract
{
    /** @var array<string, true> */
    private array $matched = [];

    /**
     * @param array<string, true> $targetNorms
     */
    public function __construct(
        private readonly array $targetNorms,
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
        if (! $node instanceof ClassMethod) {
            return null;
        }
        foreach ($node->params as $param) {
            $this->checkParam($param);
        }

        return null;
    }

    private function checkParam(Param $param): void
    {
        $t = $param->type;
        if ($t === null) {
            return;
        }
        $this->walkType($t);
    }

    private function walkType(Node $type): void
    {
        if ($type instanceof NullableType) {
            $this->walkType($type->type);

            return;
        }
        if ($type instanceof UnionType) {
            foreach ($type->types as $t) {
                $this->walkType($t);
            }

            return;
        }
        if ($type instanceof FullyQualified) {
            $this->maybeMark($type->toString());
        } elseif ($type instanceof Name) {
            $this->maybeMark($type->toString());
        }
    }

    private function maybeMark(string $name): void
    {
        $n = strtolower(ltrim($name, '\\'));
        if (isset($this->targetNorms[$n])) {
            $this->matched[$n] = true;
        }
    }
}
