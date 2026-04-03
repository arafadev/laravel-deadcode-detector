<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

/**
 * Iterates PHP files under configured scan paths and common Laravel roots.
 */
final class ProjectPhpIterator
{
    /**
     * @param list<string> $scanPaths
     *
     * @return \Generator<string>
     */
    public static function iterate(
        PhpFileScanner $scanner,
        array $scanPaths,
        PathExcludeMatcher $excludeMatcher,
        bool $includeBootstrapRoutesConfig = true,
    ): \Generator {
        foreach ($scanPaths as $base) {
            if (! is_string($base) || $base === '' || ! is_dir($base)) {
                continue;
            }
            if ($excludeMatcher->shouldExclude($base)) {
                continue;
            }
            foreach ($scanner->scanDirectoryLazy($base) as $file) {
                $r = $file->getRealPath();
                if ($r !== false && ! $excludeMatcher->shouldExclude($r)) {
                    yield $r;
                }
            }
        }

        if (! $includeBootstrapRoutesConfig || ! function_exists('base_path')) {
            return;
        }

        foreach (['routes', 'config', 'database', 'bootstrap'] as $dir) {
            $dirPath = base_path($dir);
            if (! is_dir($dirPath)) {
                continue;
            }
            if ($excludeMatcher->shouldExclude($dirPath)) {
                continue;
            }
            foreach ($scanner->scanDirectoryLazy($dirPath) as $file) {
                $r = $file->getRealPath();
                if ($r !== false && ! $excludeMatcher->shouldExclude($r)) {
                    yield $r;
                }
            }
        }
    }

    public static function isExcluded(string $path, PathExcludeMatcher $excludeMatcher): bool
    {
        return $excludeMatcher->shouldExclude($path);
    }
}
