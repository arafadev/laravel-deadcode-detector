<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use SplFileInfo;
use Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Arafa\DeadcodeDetector\Support\AstParserFactory;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;

class RoutesAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly PhpFileScanner $scanner,
        private readonly array $scanPaths,
        private readonly array $excludePaths = [],
    ) {}

    public function getName(): string
    {
        return 'routes';
    }

    public function getDescription(): string
    {
        return 'Finds named routes that are never referenced via route() helper or @route directive.';
    }

    public function analyze(): array
    {
        $routeFiles = $this->findRouteFiles();

        if ($routeFiles === []) {
            return [];
        }

        $namedRoutes = [];
        $extractor = new NamedRouteExtractorVisitor();

        foreach ($routeFiles as $file) {
            $path = $file->getRealPath();
            if ($path === false || $this->isExcluded($path)) {
                continue;
            }

            $stmts = $this->parseStatements($path);
            if ($stmts === null) {
                continue;
            }

            $extractor->setCurrentFile($path);
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($extractor);
            $traverser->traverse($stmts);

            foreach ($extractor->pullNamedRoutesForFile() as $route) {
                $namedRoutes[] = $route;
            }
        }

        if ($namedRoutes === []) {
            return [];
        }

        $usedNames = $this->collectUsedRouteNames();

        $results = [];

        foreach ($namedRoutes as $routeInfo) {
            $name = $routeInfo['name'];
            if (isset($usedNames[$name])) {
                continue;
            }

            $results[] = DeadCodeResult::fromArray([
                'analyzerName'   => $this->getName(),
                'type'           => 'route',
                'filePath'       => $routeInfo['file'],
                'className'      => null,
                'methodName'     => $name,
                'lastModified'   => date('Y-m-d H:i:s', filemtime($routeInfo['file'])),
                'isSafeToDelete' => false,
            ]);
        }

        return $results;
    }

    /**
     * @return array<string, true>
     */
    private function collectUsedRouteNames(): array
    {
        /** @var array<string, true> */
        $used = [];

        $collector = new RouteNameUsageCollector();
        foreach ($this->iteratePhpSourcesForRouteUsage() as $path) {
            $stmts = $this->parseStatements($path);
            if ($stmts === null) {
                continue;
            }

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($collector);
            $traverser->traverse($stmts);
        }

        foreach ($collector->getUsedRouteNames() as $n => $_) {
            $used[$n] = true;
        }

        foreach ($this->iterateBladeFiles() as $path) {
            $this->collectRouteNamesFromBlade($path, $used);
        }

        return $used;
    }

    /**
     * @return \Generator<string>
     */
    private function iteratePhpSourcesForRouteUsage(): \Generator
    {
        foreach ($this->scanPaths as $basePath) {
            foreach ($this->scanner->scanDirectory($basePath) as $file) {
                $real = $file->getRealPath();
                if ($real !== false && ! $this->isExcluded($real)) {
                    yield $real;
                }
            }
        }

        $routesDir = base_path('routes');
        if (is_dir($routesDir)) {
            foreach ($this->scanner->scanDirectory($routesDir) as $file) {
                $real = $file->getRealPath();
                if ($real !== false && ! $this->isExcluded($real)) {
                    yield $real;
                }
            }
        }
    }

    /**
     * @param array<string, true> $used
     */
    private function collectRouteNamesFromBlade(string $path, array &$used): void
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            return;
        }

        if (
            preg_match_all(
                '/(?:^|[^\w])route\s*\(\s*[\'"]([^\'"]+)[\'"]/',
                $content,
                $m
            )
        ) {
            foreach ($m[1] as $name) {
                $used[$name] = true;
            }
        }

        if (preg_match_all('/@route\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $m2)) {
            foreach ($m2[1] as $name) {
                $used[$name] = true;
            }
        }
    }

    /**
     * @return \Generator<string>
     */
    private function iterateBladeFiles(): \Generator
    {
        $viewsDir = resource_path('views');
        if (! is_dir($viewsDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($viewsDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $real = $file->getRealPath();
            if ($real !== false && ! $this->isExcluded($real)) {
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
    private function findRouteFiles(): array
    {
        $routesDir = base_path('routes');

        if (! is_dir($routesDir)) {
            return [];
        }

        return $this->scanner->scanDirectory($routesDir);
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
}

/** Extracts ->name('...') from route definitions with group name prefixes. */
final class NamedRouteExtractorVisitor extends NodeVisitorAbstract
{
    private string $currentFile = '';

    /** @var array<int, string> */
    private array $prefixStack = [''];

    /** @var list<array{name: string, file: string, line: int}> */
    private array $batch = [];

    /** @var array<int, true> object ids of ->name() nodes that only prefix a ->group() */
    private array $skipNameNodeIds = [];

    public function setCurrentFile(string $path): void
    {
        $this->currentFile = $path;
        $this->prefixStack = [''];
        $this->batch       = [];
        $this->skipNameNodeIds = [];
    }

    /**
     * @return list<array{name: string, file: string, line: int}>
     */
    public function pullNamedRoutesForFile(): array
    {
        $out       = $this->batch;
        $this->batch = [];

        return $out;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof MethodCall && $this->isMethodNamed($node, 'group')) {
            $prefix = $this->collectAllNamePrefixesFromChain($node->var);
            $this->prefixStack[] = end($this->prefixStack) . $prefix;
            $this->markNameChainBeforeGroup($node->var);
        }

        if ($node instanceof MethodCall && $this->isMethodNamed($node, 'name')) {
            if (isset($this->skipNameNodeIds[spl_object_id($node)])) {
                return null;
            }

            $str = $this->firstStringArg($node);
            if ($str !== null) {
                $full = end($this->prefixStack) . $str;
                $line = $node->getStartLine() ?? 0;
                $this->batch[] = [
                    'name' => $full,
                    'file' => $this->currentFile,
                    'line' => $line,
                ];
            }
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof MethodCall && $this->isMethodNamed($node, 'group')) {
            array_pop($this->prefixStack);
        }

        return null;
    }

    private function isMethodNamed(MethodCall $node, string $name): bool
    {
        $n = $node->name;
        if ($n instanceof Identifier) {
            return $n->name === $name;
        }

        return false;
    }

    private function firstStringArg(MethodCall $node): ?string
    {
        if (! isset($node->args[0])) {
            return null;
        }

        $v = $node->args[0]->value ?? null;

        return $v instanceof String_ ? $v->value : null;
    }

    /**
     * Concatenates every ->name('...') segment on the chain before ->group()
     * (outer Route::name('a') first, then inner ->name('b'), matching Laravel ordering).
     */
    private function collectAllNamePrefixesFromChain(?Node $expr): string
    {
        $parts   = [];
        $current = $expr;

        while ($current instanceof MethodCall) {
            if ($this->isMethodNamed($current, 'name')) {
                $str = $this->firstStringArg($current);
                if ($str !== null) {
                    $parts[] = $str;
                }
            }
            $current = $current->var;
        }

        return implode('', array_reverse($parts));
    }

    /**
     * Names on the chain immediately before ->group() are prefixes, not final route names.
     */
    private function markNameChainBeforeGroup(?Node $expr): void
    {
        $current = $expr;

        while ($current instanceof MethodCall) {
            if ($this->isMethodNamed($current, 'name')) {
                $this->skipNameNodeIds[spl_object_id($current)] = true;
            }
            $current = $current->var;
        }
    }
}

/**
 * Collects route name strings from route($name), redirect()->route($name), URL::route, etc.
 */
final class RouteNameUsageCollector extends NodeVisitorAbstract
{
    /** @var array<string, true> */
    private array $used = [];

    /**
     * @return array<string, true>
     */
    public function getUsedRouteNames(): array
    {
        return $this->used;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof FuncCall) {
            $fname = $this->resolveFuncCallName($node);
            if ($fname === 'route' && isset($node->args[0]) && $node->args[0]->value instanceof String_) {
                $this->used[$node->args[0]->value->value] = true;
            }

            return null;
        }

        if ($node instanceof MethodCall && $this->methodCallEndsWithRoute($node)) {
            if (isset($node->args[0]) && $node->args[0]->value instanceof String_) {
                $this->used[$node->args[0]->value->value] = true;
            }
        }

        if ($node instanceof StaticCall) {
            $method = $this->identifierToString($node->name);
            if (strtolower($method) === 'route' && isset($node->args[0]) && $node->args[0]->value instanceof String_) {
                $this->used[$node->args[0]->value->value] = true;
            }
        }

        return null;
    }

    private function methodCallEndsWithRoute(MethodCall $node): bool
    {
        if ($this->identifierToString($node->name) === 'route') {
            return true;
        }

        return false;
    }

    private function resolveFuncCallName(FuncCall $node): ?string
    {
        $n = $node->name;
        if ($n instanceof Name) {
            return $n->getLast();
        }

        if ($n instanceof Node\Name\FullyQualified) {
            return $n->getLast();
        }

        return null;
    }

    private function identifierToString(Node $name): string
    {
        if ($name instanceof Identifier) {
            return $name->name;
        }

        return '';
    }
}
