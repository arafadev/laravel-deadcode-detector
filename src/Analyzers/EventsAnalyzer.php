<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
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

class EventsAnalyzer implements AnalyzerInterface
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
        return function_exists('app_path') ? [app_path('Events')] : [];
    }

    public function getName(): string
    {
        return 'events';
    }

    public function getDescription(): string
    {
        return 'Finds event classes that are never dispatched and not registered in EventServiceProvider.';
    }

    public function analyze(): array
    {
        $roots = $this->exclusiveScanRoots();
        if ($roots === []) {
            return [];
        }

        /** @var array<string, array{file: SplFileInfo, fqcn: string}> $events */
        $events = [];
        foreach ($roots as $root) {
            foreach ($this->scanner->scanDirectoryLazy($root) as $file) {
                $path = $file->getRealPath();
                if ($path === false || $this->pathExclude->shouldExclude($path)) {
                    continue;
                }
                $fqcn = $this->extractOneClassFqcnFromFile($path);
                if ($fqcn === null) {
                    continue;
                }
                $events[$this->norm($fqcn)] = ['file' => $file, 'fqcn' => $fqcn];
            }
        }

        if ($events === []) {
            return [];
        }

        $listenEvents = $this->collectListenEventNorms();
        $dispatched   = $this->collectDispatchedEventNorms();
        $hierarchy    = ExtendsImplementsAndTraitsIndex::build(
            $this->scanner,
            $this->scanPaths,
            $this->pathExclude,
        );

        $results = [];
        foreach ($events as $norm => $meta) {
            if (isset($dispatched[$norm]) || isset($listenEvents[$norm]) || isset($hierarchy[$norm])) {
                continue;
            }
            $p = $meta['file']->getRealPath();
            if ($p === false) {
                continue;
            }
            $results[] = DeadCodeResult::fromArray([
                'analyzerName'   => $this->getName(),
                'type'           => 'event',
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

        return ScanPathResolver::dedicatedAnalyzerRoots('events', 'Events', $deadcode);
    }

    /**
     * @return array<string, true>
     */
    private function collectListenEventNorms(): array
    {
        $norms = [];
        foreach ($this->iterateProviderPhpFiles() as $path) {
            $stmts = $this->parseStatements($path);
            if ($stmts === null) {
                continue;
            }
            $v = new EventListenMapVisitor();
            $tr = new NodeTraverser();
            $tr->addVisitor(new NameResolver());
            $tr->addVisitor($v);
            $tr->traverse($stmts);
            foreach ($v->getEventNorms() as $n => $_) {
                $norms[$n] = true;
            }
        }

        return $norms;
    }

    /**
     * @return array<string, true>
     */
    private function collectDispatchedEventNorms(): array
    {
        $collector = new EventDispatchCollector();
        foreach (ProjectPhpIterator::iterate($this->scanner, $this->scanPaths, $this->pathExclude) as $path) {
            $stmts = $this->parseStatements($path);
            if ($stmts === null) {
                continue;
            }
            $tr = new NodeTraverser();
            $tr->addVisitor(new NameResolver());
            $tr->addVisitor($collector);
            $tr->traverse($stmts);
        }

        return $collector->getDispatchedNorm();
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

final class EventListenMapVisitor extends NodeVisitorAbstract
{
    /** @var array<string, true> */
    private array $eventNorms = [];

    /**
     * @return array<string, true>
     */
    public function getEventNorms(): array
    {
        return $this->eventNorms;
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
                    if ($item === null || $item->key === null) {
                        continue;
                    }
                    $ev = $this->fqcnFromClassConst($item->key);
                    if ($ev !== null) {
                        $this->eventNorms[strtolower(ltrim($ev, '\\'))] = true;
                    }
                }
            }
        }

        return null;
    }

    private function fqcnFromClassConst(Node $expr): ?string
    {
        if (! $expr instanceof ClassConstFetch || ! $expr->name instanceof Identifier || strtolower($expr->name->name) !== 'class') {
            return null;
        }
        $c = $expr->class;
        if ($c instanceof FullyQualified) {
            return $c->toString();
        }
        if ($c instanceof Name) {
            return $c->toString();
        }

        return null;
    }
}

final class EventDispatchCollector extends NodeVisitorAbstract
{
    /** @var array<string, true> */
    private array $dispatched = [];

    /**
     * @return array<string, true>
     */
    public function getDispatchedNorm(): array
    {
        return $this->dispatched;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof StaticCall && $node->name instanceof Identifier) {
            $m = strtolower($node->name->name);
            if (in_array($m, ['dispatch', 'dispatchif', 'dispatchunless'], true)) {
                $this->addClassFx($node->class);
            }
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        // Resolve New_::class and ClassConstFetch::class after children (NameResolver runs on enter New_).
        if ($node instanceof StaticCall && $node->name instanceof Identifier) {
            $m = strtolower($node->name->name);
            if ($m === 'dispatch' && $this->isEventFacade($node->class)) {
                $this->collectDispatchArgs($node->args);
            }

            return null;
        }

        if ($node instanceof MethodCall && $node->name instanceof Identifier && strtolower($node->name->name) === 'dispatch') {
            $this->collectDispatchArgs($node->args);
        }

        if ($node instanceof FuncCall) {
            $fn = $this->funcName($node);
            if (($fn === 'event' || $fn === 'broadcast') && isset($node->args[0])) {
                $this->collectDispatchArgs([$node->args[0]]);
            }
        }

        return null;
    }

    /**
     * @param array<int, Arg> $args
     */
    private function collectDispatchArgs(array $args): void
    {
        foreach ($args as $arg) {
            $v = $arg->value ?? null;
            if ($v instanceof New_) {
                $this->addClassFx($v->class);
            }
            if ($v instanceof ClassConstFetch) {
                $this->addClassFx($v->class);
            }
        }
    }

    private function isEventFacade(?Node $class): bool
    {
        if ($class instanceof Name) {
            return $class->getLast() === 'Event';
        }
        if ($class instanceof FullyQualified) {
            return str_ends_with($class->toString(), '\\Facades\\Event');
        }

        return false;
    }

    private function addClassFx(?Node $class): void
    {
        if ($class instanceof FullyQualified || $class instanceof Name) {
            $this->dispatched[strtolower(ltrim($class->toString(), '\\'))] = true;
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
