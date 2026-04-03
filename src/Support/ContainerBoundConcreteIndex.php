<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;

/**
 * Collects normalized FQCNs used as concrete implementations in app()->bind/singleton/scoped(..., Concrete::class).
 */
final class ContainerBoundConcreteIndex
{
    /** @var array<string, true>|null */
    private static ?array $memo = null;

    private static string $memoKey = '';

    /**
     * @param list<string> $scanPaths
     *
     * @return array<string, true> normalized fqcn => true
     */
    public static function getOrBuild(
        PhpFileScanner $scanner,
        array $scanPaths,
        PathExcludeMatcher $exclude,
    ): array {
        $key = hash('sha256', serialize([$scanPaths, $exclude->cacheKey()]));
        if (self::$memo !== null && self::$memoKey === $key) {
            return self::$memo;
        }

        /** @var array<string, true> $out */
        $out = [];

        foreach ($scanPaths as $base) {
            $prov = rtrim((string) $base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Providers';
            if (! is_dir($prov)) {
                continue;
            }
            foreach ($scanner->scanDirectoryLazy($prov) as $file) {
                $path = $file->getRealPath();
                if ($path === false || $exclude->shouldExclude($path)) {
                    continue;
                }
                $stmts = PhpAstParser::parseFile($path);
                if ($stmts === null) {
                    continue;
                }
                $tr = new NodeTraverser();
                $tr->addVisitor(new NameResolver());
                $tr->addVisitor(new ContainerConcreteBindingCollector($out));
                $tr->traverse($stmts);
            }
        }

        self::$memo    = $out;
        self::$memoKey = $key;

        return $out;
    }

    public static function clearMemo(): void
    {
        self::$memo    = null;
        self::$memoKey = '';
    }
}

/** @internal */
final class ContainerConcreteBindingCollector extends NodeVisitorAbstract
{
    /**
     * @param array<string, true> $out
     */
    public function __construct(
        private array &$out,
    ) {}

    public function enterNode(Node $node): ?int
    {
        if (! $node instanceof MethodCall || ! $node->name instanceof Identifier) {
            return null;
        }

        $m = $node->name->name;
        if (! in_array($m, ['bind', 'singleton', 'scoped'], true)) {
            return null;
        }

        $a1 = $node->args[1]->value ?? null;
        if (! $a1 instanceof ClassConstFetch) {
            return null;
        }

        $c = $a1->class;
        if ($c instanceof FullyQualified) {
            $n = strtolower(ltrim($c->toString(), '\\'));
        } elseif ($c instanceof Name) {
            $n = strtolower(ltrim($c->toString(), '\\'));
        } else {
            return null;
        }

        if ($n !== '') {
            $this->out[$n] = true;
        }

        return null;
    }
}
