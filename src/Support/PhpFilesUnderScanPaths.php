<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use SplFileInfo;

/**
 * De-duplicated PHP file paths across multiple scan roots.
 */
final class PhpFilesUnderScanPaths
{
    /**
     * @param list<string> $scanPaths
     *
     * @return \Generator<string>
     */
    public static function eachUniqueRealPath(
        PhpFileScanner $scanner,
        array $scanPaths,
        PathExcludeMatcher $exclude,
    ): \Generator {
        /** @var array<string, true> $seen */
        $seen = [];
        foreach ($scanPaths as $base) {
            if (! is_string($base) || $base === '' || ! is_dir($base)) {
                continue;
            }
            if ($exclude->shouldExclude($base)) {
                continue;
            }
            /** @var SplFileInfo $file */
            foreach ($scanner->scanDirectoryLazy($base) as $file) {
                $r = $file->getRealPath();
                if ($r === false || $exclude->shouldExclude($r)) {
                    continue;
                }
                $k = strtolower(str_replace('\\', '/', $r));
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                yield $r;
            }
        }
    }
}
