<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

/**
 * CLI-only: merged scan scope size for progress/summary (mirrors per-analyzer path resolution).
 */
final class CliScanPreflight
{
    /**
     * Count unique .php files under all roots an enabled analyzer set would use.
     *
     * @param array<string, string> $enabledAnalyzers config key or FQCN => analyzer class FQCN
     *
     * @return int>=0
     */
    public static function countUniquePhpFilesInMergedScope(
        PhpFileScanner $scanner,
        PathExcludeMatcher $exclude,
        array $enabledAnalyzers,
    ): int {
        $deadcode = [];
        try {
            $cfg = config('deadcode', []);
            if (is_array($cfg)) {
                $deadcode = $cfg;
            }
        } catch (\Throwable) {
            $deadcode = [];
        }

        $global = ScanPathResolver::globalScanPaths($deadcode);
        /** @var list<string> $roots */
        $roots = [];

        foreach ($enabledAnalyzers as $configKey => $analyzerClass) {
            if (! is_string($analyzerClass) || ! class_exists($analyzerClass)) {
                continue;
            }
            $key = is_string($configKey) ? $configKey : $analyzerClass;
            foreach (ScanPathResolver::analyzerScanPaths($key, $analyzerClass, $global, $deadcode) as $p) {
                if (is_string($p) && $p !== '') {
                    $roots[] = $p;
                }
            }
        }

        $roots = ProjectStructureScanner::dedupePaths($roots);

        /** @var array<string, true> $seen */
        $seen  = [];
        $count = 0;

        foreach ($roots as $base) {
            if ($base === '' || ! is_dir($base) || $exclude->shouldExclude($base)) {
                continue;
            }
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
                ++$count;
            }
        }

        return $count;
    }
}
