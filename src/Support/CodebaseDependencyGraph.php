<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

/**
 * @deprecated Use {@see DependencyGraphEngine}. Kept as a discoverable alias for older call sites.
 */
final class CodebaseDependencyGraph
{
    /**
     * @param list<string> $scanPaths
     */
    public static function getOrBuild(
        PhpFileScanner $scanner,
        array $scanPaths,
        PathExcludeMatcher $excludeMatcher,
    ): DependencyGraphEngine {
        return DependencyGraphEngine::getOrBuild($scanner, $scanPaths, $excludeMatcher);
    }

    public static function clearMemo(): void
    {
        DependencyGraphEngine::clearMemo();
    }
}
