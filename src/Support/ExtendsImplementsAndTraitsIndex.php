<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;

/**
 * Builds a set of class/interface/trait FQCNs that are referenced as:
 * - parent class (extends)
 * - interface (implements)
 * - extended interface (interface extends)
 * - trait (use inside a class)
 *
 * Used to avoid false "unused" positives for base controllers, interfaces, traits, etc.
 */
final class ExtendsImplementsAndTraitsIndex
{
    /** @var array<string, true>|null */
    private static ?array $memo = null;

    private static string $memoKey = '';

    /**
     * Normalized keys: strtolower(ltrim(fqcn, '\\')).
     *
     * @return array<string, true>
     */
    public static function build(
        PhpFileScanner $scanner,
        array $scanPaths,
        PathExcludeMatcher $excludeMatcher,
    ): array {
        $key = md5(serialize([$scanPaths, $excludeMatcher->cacheKey()]));
        if (self::$memo !== null && self::$memoKey === $key) {
            return self::$memo;
        }

        $used    = [];
        $visitor = new HierarchyTargetVisitor($used);

        foreach (self::eachPhpFile($scanner, $scanPaths, $excludeMatcher) as $path) {
            $stmts = CachedAstParser::parseFile($path);
            if ($stmts === null) {
                continue;
            }

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($visitor);
            $traverser->traverse($stmts);
        }

        self::$memo    = $used;
        self::$memoKey = $key;

        return $used;
    }

    /**
     * @return \Generator<string>
     */
    private static function eachPhpFile(
        PhpFileScanner $scanner,
        array $scanPaths,
        PathExcludeMatcher $excludeMatcher,
    ): \Generator {
        foreach ($scanPaths as $basePath) {
            if (! is_string($basePath) || $basePath === '' || ! is_dir($basePath)) {
                continue;
            }
            if ($excludeMatcher->shouldExclude($basePath)) {
                continue;
            }
            foreach ($scanner->scanDirectoryLazy($basePath) as $file) {
                $real = $file->getRealPath();
                if ($real === false || $excludeMatcher->shouldExclude($real)) {
                    continue;
                }
                yield $real;
            }
        }

        if (! function_exists('base_path')) {
            return;
        }

        foreach (['routes', 'config', 'database'] as $dir) {
            $dirPath = base_path($dir);
            if (! is_dir($dirPath)) {
                continue;
            }
            if ($excludeMatcher->shouldExclude($dirPath)) {
                continue;
            }
            foreach ($scanner->scanDirectoryLazy($dirPath) as $file) {
                $real = $file->getRealPath();
                if ($real === false || $excludeMatcher->shouldExclude($real)) {
                    continue;
                }
                yield $real;
            }
        }
    }
}

/**
 * @phpstan-type UsedSet array<string, true>
 */
final class HierarchyTargetVisitor extends NodeVisitorAbstract
{
    /**
     * @param UsedSet $used
     */
    public function __construct(
        private array &$used,
    ) {}

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Class_) {
            if ($node->extends !== null) {
                $this->addNameNode($node->extends);
            }
            foreach ($node->implements as $iface) {
                $this->addNameNode($iface);
            }
        }

        if ($node instanceof Interface_) {
            foreach ($node->extends as $iface) {
                $this->addNameNode($iface);
            }
        }

        if ($node instanceof Enum_) {
            foreach ($node->implements as $iface) {
                $this->addNameNode($iface);
            }
        }

        if ($node instanceof TraitUse) {
            foreach ($node->traits as $traitName) {
                $this->addNameNode($traitName);
            }
        }

        return null;
    }

    private function addNameNode(Node $nameNode): void
    {
        if ($nameNode instanceof FullyQualified) {
            $this->mark($nameNode->toString());

            return;
        }

        if ($nameNode instanceof Name) {
            $this->mark($nameNode->toString());
        }
    }

    private function mark(string $fqcnOrRelative): void
    {
        $n = strtolower(ltrim($fqcnOrRelative, '\\'));
        if ($n !== '') {
            $this->used[$n] = true;
        }
    }
}
