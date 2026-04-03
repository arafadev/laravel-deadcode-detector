<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

/**
 * Iterates .js / .ts / .vue files under a base path (for route-name usage, etc.).
 */
final class FrontendAssetScanner
{
    private const EXTENSIONS = ['js', 'ts', 'vue', 'tsx', 'jsx'];

    /**
     * @return \Generator<string> absolute file paths
     */
    public static function iterateFiles(string $root, PathExcludeMatcher $excludeMatcher): \Generator
    {
        if (! is_dir($root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $ext = strtolower($file->getExtension());
            if (! in_array($ext, self::EXTENSIONS, true)) {
                continue;
            }

            $real = $file->getRealPath();
            if ($real === false || $excludeMatcher->shouldExclude($real)) {
                continue;
            }

            yield $real;
        }
    }
}
