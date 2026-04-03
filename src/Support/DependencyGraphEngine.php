<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;

/**
 * Dependency graph built from AST (after file collection): class references, instantiations,
 * method calls, route → controller actions, and Blade include/extends links for views.
 *
 * Use this for conservative reachability (routes + call graph + view closure), not as proof of
 * total unused code — dynamic calls and container resolution are not fully modeled.
 */
final class DependencyGraphEngine
{
    private static ?string $memoKey = null;

    private static ?self $memo = null;

    /** classNorm => [ referencingFileNorm => true ] */
    private array $classReferencedFromFiles = [];

    /** callerMethodKey => [ calleeMethodKey => true ] (directed) */
    private array $methodOutgoing = [];

    /** @var list<string> route-registered actions: lowerfqcn::method */
    private array $routeEntryMethodKeys = [];

    /** @var array<string, list<string>> blade fromDot => to dots */
    private array $bladeEdges = [];

    /** @var array<string, true> views referenced from PHP roots */
    private array $phpViewRoots = [];

    /** Dynamic view() / View::make first-segment hints (lowercase) */
    private array $dynamicViewPrefixes = [];

    /** @var array<string, true>|null */
    private ?array $reachableMethodsFromRoutesCache = null;

    /** @var array<string, true>|null */
    private ?array $reachableViewDotsCache = null;

    private function __construct() {}

    /**
     * @param list<string> $scanPaths
     */
    public static function getOrBuild(
        PhpFileScanner $scanner,
        array $scanPaths,
        PathExcludeMatcher $excludeMatcher,
    ): self {
        $key = hash('sha256', serialize([$scanPaths, $excludeMatcher->cacheKey()]));
        if (self::$memo !== null && self::$memoKey === $key) {
            return self::$memo;
        }

        $engine = new self();
        $engine->build($scanner, $scanPaths, $excludeMatcher);

        self::$memo    = $engine;
        self::$memoKey = $key;

        return $engine;
    }

    public static function clearMemo(): void
    {
        self::$memo    = null;
        self::$memoKey = null;
    }

    /**
     * Legacy class-level signal: other PHP files reference this class via new/static/::class/instanceof.
     */
    public function isClassReferencedFromOtherPhpFiles(string $classNorm, string $declaringFile): bool
    {
        $classNorm = strtolower(ltrim($classNorm, '\\'));
        if (! isset($this->classReferencedFromFiles[$classNorm])) {
            return false;
        }

        $declNorm = $this->normFile($declaringFile);
        foreach (array_keys($this->classReferencedFromFiles[$classNorm]) as $ref) {
            if ($ref !== $declNorm) {
                return true;
            }
        }

        return false;
    }

    /**
     * True if the method is a registered route action or is reachable from one via resolved static/instance calls.
     */
    public function isMethodReachableFromRouteEntries(string $classNorm, string $methodName): bool
    {
        $k = strtolower(ltrim($classNorm, '\\')) . '::' . strtolower($methodName);

        return isset($this->reachableMethodsFromRouteEntries()[$k]);
    }

    /**
     * @return array<string, true> keys lowerfqcn::method
     */
    public function reachableMethodsFromRouteEntries(): array
    {
        if ($this->reachableMethodsFromRoutesCache !== null) {
            return $this->reachableMethodsFromRoutesCache;
        }

        /** @var array<string, true> $reachable */
        $reachable = [];
        $queue     = $this->routeEntryMethodKeys;
        foreach ($queue as $k) {
            if ($k !== '') {
                $reachable[$k] = true;
            }
        }

        $head = 0;
        while ($head < count($queue)) {
            $u = $queue[$head];
            ++$head;
            if ($u === '') {
                continue;
            }
            foreach (array_keys($this->methodOutgoing[$u] ?? []) as $v) {
                if ($v !== '' && ! isset($reachable[$v])) {
                    $reachable[$v] = true;
                    $queue[] = $v;
                }
            }
        }

        $this->reachableMethodsFromRoutesCache = $reachable;

        return $reachable;
    }

    /**
     * Merge graph reachability into controller method keys used by ControllersAnalyzer.
     *
     * @param array<string, true> $globallyCalledControllerMethods
     * @param array<string, true> $controllerFqcnLowerLookup
     */
    public function mergeReachableControllerMethodsFromRoutesInto(
        array &$globallyCalledControllerMethods,
        array $controllerFqcnLowerLookup,
    ): void {
        foreach (array_keys($this->reachableMethodsFromRouteEntries()) as $key) {
            $parts = explode('::', $key, 2);
            if (count($parts) !== 2) {
                continue;
            }
            if (isset($controllerFqcnLowerLookup[$parts[0]])) {
                $globallyCalledControllerMethods[$key] = true;
            }
        }
    }

    /**
     * View dot names reachable from PHP (incl. Inertia) or transitively via Blade.
     *
     * @return array<string, true>
     */
    public function reachableViewDots(): array
    {
        if ($this->reachableViewDotsCache !== null) {
            return $this->reachableViewDotsCache;
        }

        $reachable = $this->phpViewRoots;
        $changed   = true;
        while ($changed) {
            $changed = false;
            foreach ($this->bladeEdges as $from => $tos) {
                if (! isset($reachable[$from])) {
                    continue;
                }
                foreach ($tos as $to) {
                    if ($to !== '' && ! isset($reachable[$to])) {
                        $reachable[$to] = true;
                        $changed        = true;
                    }
                }
            }
        }

        $this->reachableViewDotsCache = $reachable;

        return $reachable;
    }

    /**
     * @return list<string>
     */
    public function routeEntryMethodKeys(): array
    {
        return $this->routeEntryMethodKeys;
    }

    /**
     * First path segments inferred when view names are built from string concat / interpolation.
     *
     * @return list<string>
     */
    public function dynamicViewPrefixes(): array
    {
        return array_keys($this->dynamicViewPrefixes);
    }

    public function recordReferenceFromFileToClassNorm(string $referencingFile, string $classNorm): void
    {
        if ($classNorm === '') {
            return;
        }
        $fileNorm = $this->normFile($referencingFile);
        $this->classReferencedFromFiles[strtolower(ltrim($classNorm, '\\'))][$fileNorm] = true;
    }

    /**
     * @internal
     */
    public function addMethodCallEdge(string $callerKey, string $calleeKey): void
    {
        if ($callerKey === '' || $calleeKey === '') {
            return;
        }
        $this->methodOutgoing[$callerKey][$calleeKey] = true;
        $this->reachableMethodsFromRoutesCache = null;
    }

    private static function classBasenameFqcn(string $fqcn): string
    {
        return function_exists('class_basename')
            ? class_basename($fqcn)
            : basename(str_replace('\\', '/', $fqcn));
    }

    private function normFile(string $path): string
    {
        $rp = realpath($path);

        return strtolower(str_replace('\\', '/', $rp !== false ? $rp : $path));
    }

    private function build(
        PhpFileScanner $scanner,
        array $scanPaths,
        PathExcludeMatcher $excludeMatcher,
    ): void {
        /** @var array<string, list<string>> $shortBasenameToFqcns */
        $shortBasenameToFqcns = [];

        foreach (ProjectPhpIterator::iterate($scanner, $scanPaths, $excludeMatcher) as $path) {
            $fqcn = PhpClassAstHelper::fqcnFromFile($path);
            if ($fqcn === null) {
                continue;
            }
            $short = strtolower(self::classBasenameFqcn($fqcn));
            $shortBasenameToFqcns[$short][] = $fqcn;
        }

        $actionExtractor = new ControllerRouteActionExtractor($shortBasenameToFqcns);

        $viewRefs = new ViewReferenceCollector();

        foreach (ProjectPhpIterator::iterate($scanner, $scanPaths, $excludeMatcher) as $path) {
            $stmts = CachedAstParser::parseFile($path);
            if ($stmts === null) {
                continue;
            }
            $tr = new NodeTraverser();
            $tr->addVisitor(new NameResolver());
            $tr->addVisitor(new GraphClassSymbolVisitor($path, $this));
            $tr->addVisitor(new GraphMethodCallVisitor($path, $this));
            $tr->addVisitor($viewRefs);
            $tr->traverse($stmts);
        }

        foreach (array_keys($viewRefs->getDotNames()) as $d) {
            if ($d !== '') {
                $this->phpViewRoots[$d] = true;
            }
        }
        foreach ($viewRefs->getDynamicViewPrefixes() as $prefix) {
            $p = strtolower($prefix);
            if ($p !== '') {
                $this->dynamicViewPrefixes[$p] = true;
            }
        }

        foreach ($this->iterateRoutePhpFiles($scanner, $excludeMatcher) as $path) {
            $stmts = CachedAstParser::parseFile($path);
            if ($stmts === null) {
                continue;
            }
            $tr = new NodeTraverser();
            $tr->addVisitor(new NameResolver());
            $tr->addVisitor($actionExtractor);
            $tr->traverse($stmts);
        }

        foreach (array_keys($actionExtractor->getUsedActions()) as $k) {
            $this->routeEntryMethodKeys[] = $k;
        }

        $this->buildBladeEdges($excludeMatcher);
        $this->reachableViewDotsCache = null;
    }

    /**
     * @return \Generator<string>
     */
    private function iterateRoutePhpFiles(PhpFileScanner $scanner, PathExcludeMatcher $exclude): \Generator
    {
        if (! function_exists('base_path')) {
            return;
        }
        $routesDir = base_path('routes');
        if (! is_dir($routesDir)) {
            return;
        }
        foreach ($scanner->scanDirectoryLazy($routesDir) as $file) {
            $real = $file->getRealPath();
            if ($real !== false && ! $exclude->shouldExclude($real)) {
                yield $real;
            }
        }
    }

    private function buildBladeEdges(PathExcludeMatcher $exclude): void
    {
        if (! function_exists('resource_path')) {
            return;
        }
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
            if ($real === false || $exclude->shouldExclude($real)) {
                continue;
            }
            $fromDot = $this->bladeDotFromPath($real, $viewsDir);
            if ($fromDot === '') {
                continue;
            }
            $content = @file_get_contents($real);
            if ($content === false) {
                continue;
            }
            foreach (BladeOutgoingLinkExtractor::extractOutgoingDotNamesFromContent($content) as $to) {
                if ($to !== '') {
                    $this->bladeEdges[$fromDot][] = $to;
                }
            }
        }
    }

    private function bladeDotFromPath(string $absolutePath, string $viewsRoot): string
    {
        $base = realpath($viewsRoot);
        if ($base === false) {
            return '';
        }
        $base .= DIRECTORY_SEPARATOR;
        $relative = str_replace($base, '', $absolutePath);
        $relative = str_replace(['/', '\\'], '.', $relative);

        return (string) preg_replace('/\.blade\.php$/', '', $relative);
    }
}

final class GraphClassSymbolVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private readonly string $absoluteFile,
        private readonly DependencyGraphEngine $graph,
    ) {}

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof New_ && $node->class instanceof Name) {
            $this->name($node->class);
        }

        if ($node instanceof StaticCall && $node->class instanceof Name) {
            $this->name($node->class);
        }

        if ($node instanceof ClassConstFetch && $node->class instanceof Name) {
            $this->name($node->class);
        }

        if ($node instanceof Instanceof_ && $node->class instanceof Name) {
            $this->name($node->class);
        }

        return null;
    }

    private function name(Name $name): void
    {
        if ($name instanceof FullyQualified) {
            $fq = ltrim($name->toString(), '\\');
        } else {
            $fq = $name->toString();
            if ($fq === '' || $fq === 'self' || $fq === 'parent' || $fq === 'static') {
                return;
            }
        }

        $norm = strtolower(ltrim($fq, '\\'));
        if ($norm === '') {
            return;
        }

        $this->graph->recordReferenceFromFileToClassNorm($this->absoluteFile, $norm);
    }
}

final class GraphMethodCallVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private readonly string $absoluteFile,
        private readonly DependencyGraphEngine $graph,
    ) {}

    /** @var list<string> */
    private array $classNormStack = [];

    /** @var list<string|null> */
    private array $methodStack = [];

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Class_ || $node instanceof Trait_) {
            if ($node->name === null) {
                return null;
            }
            $fqn = $node->namespacedName ?? null;
            if ($fqn !== null) {
                $this->classNormStack[] = strtolower(ltrim($fqn->toString(), '\\'));
            }

            return null;
        }

        if ($node instanceof ClassMethod) {
            $n = $node->name->name ?? '';
            $this->methodStack[] = $n !== '' ? strtolower($n) : null;

            return null;
        }

        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            $this->handleMethodCall($node);

            return null;
        }

        if ($node instanceof StaticCall) {
            $this->handleStaticCall($node);

            return null;
        }

        if ($node instanceof New_ && $node->class instanceof Name) {
            $this->handleNew($node);

            return null;
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Class_ || $node instanceof Trait_) {
            if ($node->name === null) {
                return null;
            }
            $fqn = $node->namespacedName ?? null;
            if ($fqn !== null && $this->classNormStack !== []) {
                array_pop($this->classNormStack);
            }

            return null;
        }

        if ($node instanceof ClassMethod) {
            if ($this->methodStack !== []) {
                array_pop($this->methodStack);
            }

            return null;
        }

        return null;
    }

    private function callerKey(): ?string
    {
        $c = $this->classNormStack === [] ? null : $this->classNormStack[array_key_last($this->classNormStack)];
        $m = $this->methodStack === [] ? null : $this->methodStack[array_key_last($this->methodStack)];
        if ($c === null || $m === null || $m === '') {
            return null;
        }

        return $c . '::' . $m;
    }

    private function handleMethodCall(MethodCall|NullsafeMethodCall $node): void
    {
        $caller = $this->callerKey();
        if ($caller === null) {
            return;
        }

        if (! $node->name instanceof Identifier) {
            return;
        }

        $methodLower = strtolower($node->name->name);

        if ($this->isThisVar($node->var)) {
            $c = $this->currentClassNorm();
            if ($c !== null) {
                $this->graph->addMethodCallEdge($caller, $c . '::' . $methodLower);
            }

            return;
        }

        if ($node->var instanceof StaticCall) {
            $sc = $node->var;
            if ($this->isSelfStaticClass($sc->class) && $sc->name instanceof Identifier) {
                $parentMethod = strtolower($sc->name->name);
                $c            = $this->currentClassNorm();
                if ($c !== null) {
                    $this->graph->addMethodCallEdge($caller, $c . '::' . $parentMethod);
                }
            }
        }
    }

    private function handleStaticCall(StaticCall $node): void
    {
        $caller = $this->callerKey();
        if ($caller === null || ! $node->name instanceof Identifier) {
            return;
        }

        $targetClass = $this->resolveClassNorm($node->class);
        if ($targetClass === null) {
            return;
        }

        $m = strtolower($node->name->name);
        if ($m === 'class') {
            return;
        }

        $this->graph->addMethodCallEdge($caller, $targetClass . '::' . $m);
    }

    private function handleNew(New_ $node): void
    {
        $caller = $this->callerKey();
        if ($caller === null || ! $node->class instanceof Name) {
            return;
        }

        $targetClass = $this->resolveClassNorm($node->class);
        if ($targetClass === null) {
            return;
        }

        $this->graph->addMethodCallEdge($caller, $targetClass . '::__construct');
    }

    private function currentClassNorm(): ?string
    {
        if ($this->classNormStack === []) {
            return null;
        }

        return $this->classNormStack[array_key_last($this->classNormStack)];
    }

    private function isThisVar(Node $expr): bool
    {
        if (! $expr instanceof Variable) {
            return false;
        }

        $n = $expr->name;

        return $n === 'this'
            || ($n instanceof Identifier && $n->name === 'this');
    }

    private function isSelfStaticClass(?Node $class): bool
    {
        if (! $class instanceof Name) {
            return false;
        }

        $s = $class->toString();

        return $s === 'self' || $s === 'static';
    }

    private function resolveClassNorm(?Node $classExpr): ?string
    {
        if (! $classExpr instanceof Name) {
            return null;
        }

        if ($classExpr instanceof FullyQualified) {
            return strtolower(ltrim($classExpr->toString(), '\\'));
        }

        $s = $classExpr->toString();
        if ($s === 'self' || $s === 'static') {
            return $this->currentClassNorm();
        }
        if ($s === 'parent') {
            return null;
        }

        if (str_contains($s, '\\')) {
            return strtolower(ltrim($s, '\\'));
        }

        $resolved = $classExpr->getAttribute('resolvedName');
        if ($resolved instanceof Name) {
            return $this->resolveClassNorm($resolved);
        }

        return strtolower($s);
    }
}
