<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
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

class ObserversAnalyzer implements AnalyzerInterface
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
        return function_exists('app_path') ? [app_path('Observers')] : [];
    }

    public function getName(): string
    {
        return 'observers';
    }

    public function getDescription(): string
    {
        return 'Finds observer classes under app/Observers that are never registered via Model::observe().';
    }

    public function analyze(): array
    {
        /** @var array<string, array{file: SplFileInfo, fqcn: string}> $observers */
        $observers = [];

        foreach (PhpFilesUnderScanPaths::eachUniqueRealPath($this->scanner, $this->scanPaths, $this->pathExclude) as $path) {
            $kinds = PhpClassAstHelper::classifyFile($path);
            if ($kinds === null || ! ClassKindClassifier::isObserverCandidate($kinds, $path)) {
                continue;
            }
            $fqcn = PhpClassAstHelper::fqcnFromFile($path);
            if ($fqcn === null) {
                continue;
            }
            $observers[$this->norm($fqcn)] = ['file' => new SplFileInfo($path), 'fqcn' => $fqcn];
        }

        if ($observers === []) {
            return [];
        }

        $registered = $this->collectRegisteredObserverNorms();
        $classMap   = $this->buildDeclaredClassMap();
        $hierarchy  = ExtendsImplementsAndTraitsIndex::build(
            $this->scanner,
            $this->scanPaths,
            $this->pathExclude,
        );

        $results = [];
        foreach ($observers as $norm => $meta) {
            if (isset($registered[$norm]) || isset($hierarchy[$norm])) {
                continue;
            }
            $f = $meta['file'];
            $p = $f->getRealPath();
            if ($p === false) {
                continue;
            }

            $results[] = DeadCodeResult::fromArray([
                'analyzerName'   => $this->getName(),
                'type'           => 'observer',
                'filePath'       => $p,
                'className'      => $meta['fqcn'],
                'methodName'     => 'never_registered',
                'lastModified'   => date('Y-m-d H:i:s', $f->getMTime()),
                'isSafeToDelete' => false,
                'orphanedHint'   => true,
            ]);
        }

        foreach ($registered as $norm => $reg) {
            if (isset($classMap[$norm])) {
                continue;
            }
            $results[] = DeadCodeResult::fromArray([
                'analyzerName'   => $this->getName(),
                'type'           => 'observer',
                'filePath'       => $reg['file'],
                'className'      => $reg['fqcn'],
                'methodName'     => 'missing_class',
                'lastModified'   => date('Y-m-d H:i:s', filemtime($reg['file']) ?: time()),
                'isSafeToDelete' => false,
            ]);
        }

        return $results;
    }

    /**
     * @return array<string, true>
     */
    private function buildDeclaredClassMap(): array
    {
        $map = [];
        foreach (ProjectPhpIterator::iterate($this->scanner, $this->scanPaths, $this->pathExclude) as $path) {
            $fqcn = $this->extractOneClassFqcnFromFile($path);
            if ($fqcn !== null) {
                $map[$this->norm($fqcn)] = true;
            }
        }

        return $map;
    }

    /**
     * @return array<string, array{file: string, fqcn: string}>
     */
    private function collectRegisteredObserverNorms(): array
    {
        $collector = new ObserverRegistrationCollector();
        foreach (ProjectPhpIterator::iterate($this->scanner, $this->scanPaths, $this->pathExclude) as $path) {
            $stmts = $this->parseStatements($path);
            if ($stmts === null) {
                continue;
            }
            $collector->setCurrentFile($path);
            $tr = new NodeTraverser();
            $tr->addVisitor(new NameResolver());
            $tr->addVisitor($collector);
            $tr->traverse($stmts);
        }

        return $collector->getRegistered();
    }

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

    private function extractOneClassFqcnFromFile(string $path): ?string
    {
        $stmts = $this->parseStatements($path);
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

                        return $ns !== '' ? $ns . '\\' . $c : $c;
                    }
                }
            } elseif ($stmt instanceof Class_ && $stmt->name !== null) {
                return $stmt->name->name;
            }
        }

        return null;
    }

    private function norm(string $fqcn): string
    {
        return strtolower(ltrim($fqcn, '\\'));
    }

}

final class ObserverRegistrationCollector extends NodeVisitorAbstract
{
    private string $currentFile = '';

    /** @var array<string, array{file: string, fqcn: string}> */
    private array $registered = [];

    public function setCurrentFile(string $path): void
    {
        $this->currentFile = $path;
    }

    /**
     * @return array<string, array{file: string, fqcn: string}>
     */
    public function getRegistered(): array
    {
        return $this->registered;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Class_) {
            foreach ($node->attrGroups as $g) {
                foreach ($g->attrs as $attr) {
                    $nm = $attr->name;
                    if ($nm instanceof Name && $nm->getLast() === 'ObservedBy') {
                        foreach ($attr->args as $arg) {
                            $this->consumeObserverArg($arg->value ?? null);
                        }
                    }
                }
            }
        }

        if ($node instanceof StaticCall
            && $node->name instanceof Identifier
            && strtolower($node->name->name) === 'observe'
        ) {
            $this->consumeObserverArg($node->args[0]->value ?? null);

            return null;
        }

        if ($node instanceof MethodCall
            && $node->name instanceof Identifier
            && strtolower($node->name->name) === 'observe'
        ) {
            $this->consumeObserverArg($node->args[0]->value ?? null);
        }

        return null;
    }

    private function consumeObserverArg(?Node $expr): void
    {
        if ($expr instanceof Array_) {
            foreach ($expr->items as $item) {
                if ($item === null) {
                    continue;
                }
                $this->consumeObserverArg($item->value ?? null);
            }

            return;
        }

        if (! $expr instanceof ClassConstFetch || ! $expr->name instanceof Identifier) {
            return;
        }
        if (strtolower($expr->name->name) !== 'class') {
            return;
        }

        $c = $expr->class;
        $fqcn = null;
        if ($c instanceof FullyQualified) {
            $fqcn = $c->toString();
        } elseif ($c instanceof Name) {
            $fqcn = $c->toString();
        }
        if ($fqcn === null) {
            return;
        }

        $norm                     = strtolower(ltrim($fqcn, '\\'));
        $this->registered[$norm] = ['file' => $this->currentFile, 'fqcn' => $fqcn];
    }
}
