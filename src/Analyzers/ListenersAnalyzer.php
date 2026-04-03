<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use SplFileInfo;
use Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Arafa\DeadcodeDetector\Support\AstParserFactory;
use Arafa\DeadcodeDetector\Support\ExtendsImplementsAndTraitsIndex;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;
use Arafa\DeadcodeDetector\Support\PathExcludeMatcher;
use Arafa\DeadcodeDetector\Support\ProjectPhpIterator;
use Arafa\DeadcodeDetector\Support\ScanPathResolver;

class ListenersAnalyzer implements AnalyzerInterface
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
        return function_exists('app_path') ? [app_path('Listeners')] : [];
    }

    public function getName(): string
    {
        return 'listeners';
    }

    public function getDescription(): string
    {
        return 'Finds listener classes that are never registered in EventServiceProvider $listen.';
    }

    public function analyze(): array
    {
        $roots = $this->exclusiveScanRoots();
        if ($roots === []) {
            return [];
        }

        /** @var array<string, array{file: SplFileInfo, fqcn: string}> $listeners */
        $listeners = [];
        foreach ($roots as $root) {
            foreach ($this->scanner->scanDirectoryLazy($root) as $file) {
                $path = $file->getRealPath();
                if ($path === false || $this->pathExclude->shouldExclude($path)) {
                    continue;
                }
                if (! $this->fileHasHandleMethod($path)) {
                    continue;
                }
                $fqcn = $this->extractOneClassFqcnFromFile($path);
                if ($fqcn === null) {
                    continue;
                }
                $listeners[$this->norm($fqcn)] = ['file' => $file, 'fqcn' => $fqcn];
            }
        }

        if ($listeners === []) {
            return [];
        }

        $registered = $this->collectRegisteredListenerNorms();
        $hierarchy  = ExtendsImplementsAndTraitsIndex::build(
            $this->scanner,
            $this->scanPaths,
            $this->pathExclude,
        );

        $results = [];
        foreach ($listeners as $norm => $meta) {
            if (isset($registered[$norm]) || isset($hierarchy[$norm])) {
                continue;
            }
            $p = $meta['file']->getRealPath();
            if ($p === false) {
                continue;
            }
            $results[] = DeadCodeResult::fromArray([
                'analyzerName'   => $this->getName(),
                'type'           => 'listener',
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
     * @return list<string>
     */
    private function exclusiveScanRoots(): array
    {
        $deadcode = [];
        try {
            $c = config('deadcode', []);
            $deadcode = is_array($c) ? $c : [];
        } catch (\Throwable) {
        }

        return ScanPathResolver::dedicatedAnalyzerRoots('listeners', 'Listeners', $deadcode);
    }

    /**
     * @return array<string, true>
     */
    private function collectRegisteredListenerNorms(): array
    {
        $norms = [];
        foreach ($this->iterateProviderPhpFiles() as $path) {
            $stmts = $this->parseStatements($path);
            if ($stmts === null) {
                continue;
            }
            $v = new ListenerRegistrationFromListenVisitor();
            $tr = new NodeTraverser();
            $tr->addVisitor(new NameResolver());
            $tr->addVisitor($v);
            $tr->traverse($stmts);
            foreach ($v->getListenerNorms() as $n => $_) {
                $norms[$n] = true;
            }
        }

        return $norms;
    }

    /**
     * @return \Generator<string>
     */
    private function iterateProviderPhpFiles(): \Generator
    {
        foreach ($this->scanPaths as $base) {
            $providers = rtrim((string) $base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Providers';
            if (! is_dir($providers)) {
                continue;
            }
            foreach ($this->scanner->scanDirectoryLazy($providers) as $file) {
                $r = $file->getRealPath();
                if ($r !== false && ! $this->pathExclude->shouldExclude($r)) {
                    yield $r;
                }
            }
        }
    }

    private function fileHasHandleMethod(string $path): bool
    {
        $stmts = $this->parseStatements($path);
        if ($stmts === null) {
            return false;
        }
        $v = new class extends NodeVisitorAbstract {
            private bool $found = false;

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof ClassMethod && ! $node->isStatic() && strtolower($node->name->name) === 'handle') {
                    $this->found = true;

                    return NodeTraverser::STOP_TRAVERSAL;
                }

                return null;
            }

            public function found(): bool
            {
                return $this->found;
            }
        };
        $tr = new NodeTraverser();
        $tr->addVisitor($v);
        $tr->traverse($stmts);

        return $v->found();
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

final class ListenerRegistrationFromListenVisitor extends NodeVisitorAbstract
{
    /** @var array<string, true> */
    private array $listenerNorms = [];

    /**
     * @return array<string, true>
     */
    public function getListenerNorms(): array
    {
        return $this->listenerNorms;
    }

    public function enterNode(Node $node): ?int
    {
        if (! $node instanceof Class_) {
            return null;
        }

        $e = $node->extends;
        $isEsp = ($e instanceof FullyQualified && strcasecmp($e->toString(), 'Illuminate\Foundation\Support\Providers\EventServiceProvider') === 0)
            || ($e instanceof Name && $e->getLast() === 'EventServiceProvider');
        if (! $isEsp) {
            return null;
        }

        foreach ($node->stmts ?? [] as $stmt) {
            if (! $stmt instanceof Property || $stmt->isStatic()) {
                continue;
            }
            foreach ($stmt->props as $prop) {
                if (strtolower($prop->name->name) !== 'listen') {
                    continue;
                }
                $def = $prop->default;
                if (! $def instanceof Array_) {
                    continue;
                }
                foreach ($def->items as $item) {
                    if ($item === null) {
                        continue;
                    }
                    $val = $item->value ?? null;
                    if ($val instanceof ClassConstFetch) {
                        $this->addListenerExpr($val);
                    } elseif ($val instanceof Array_) {
                        foreach ($val->items as $sub) {
                            if ($sub === null) {
                                continue;
                            }
                            $v = $sub->value ?? null;
                            if ($v instanceof ClassConstFetch) {
                                $this->addListenerExpr($v);
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    private function addListenerExpr(ClassConstFetch $expr): void
    {
        if (! $expr->name instanceof Identifier || strtolower($expr->name->name) !== 'class') {
            return;
        }
        $c = $expr->class;
        if ($c instanceof FullyQualified) {
            $this->listenerNorms[strtolower($c->toString())] = true;
        } elseif ($c instanceof Name) {
            $this->listenerNorms[strtolower($c->toString())] = true;
        }
    }
}
