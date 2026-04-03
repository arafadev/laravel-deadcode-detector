<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

/**
 * Resolves all scan roots from config + Laravel layout before any analyzer runs.
 *
 * Merge order for {@see globalScanPaths()}:
 *  1. Auto-discovery ({@see ProjectStructureScanner::discoverGlobalRoots()}) when auto_discover is true
 *  2. scan_paths (optional manual additions)
 *  3. paths.extra (optional extensions)
 *  4. If still empty: app_path() fallback
 *
 * Per-analyzer ({@see analyzerScanPaths()}):
 *  - If analyzer_paths.{key} is a non-empty array → those paths only as “primary” (override)
 *    then global roots are merged (so reference scans still see the wider codebase).
 *  - Otherwise → convention paths from {@see ProjectStructureScanner::pathsForAnalyzer()}
 *    plus the analyzer class {@see defaultScanPaths()} when present, then global roots.
 */
final class ScanPathResolver
{
    /**
     * @param array<string, mixed> $deadcode Config tree under key "deadcode"
     *
     * @return list<string>
     */
    public static function globalScanPaths(array $deadcode): array
    {
        /** @var list<string> $paths */
        $paths = [];

        if (($deadcode['auto_discover'] ?? true) === true) {
            $paths = [...$paths, ...ProjectStructureScanner::discoverGlobalRoots()];
        }

        foreach (self::normalizeStringList($deadcode['scan_paths'] ?? null) as $p) {
            $paths[] = $p;
        }

        $pathsExtra = [];
        if (isset($deadcode['paths']) && is_array($deadcode['paths'])) {
            $extra = $deadcode['paths']['extra'] ?? [];
            $pathsExtra = self::normalizeStringList($extra);
        }
        foreach ($pathsExtra as $p) {
            $paths[] = $p;
        }

        if ($paths === [] && function_exists('app_path')) {
            $paths[] = app_path();
        }

        return ProjectStructureScanner::dedupePaths($paths);
    }

    /**
     * @param array<string, mixed>      $deadcode
     * @param class-string              $resolvedClass
     * @param list<string>              $globalScanPaths
     *
     * @return list<string>
     */
    public static function analyzerScanPaths(
        string $configKey,
        string $resolvedClass,
        array $globalScanPaths,
        array $deadcode,
    ): array {
        /** @var list<string> $primary */
        $primary = [];

        $analyzerPaths = $deadcode['analyzer_paths'] ?? [];
        $custom        = is_array($analyzerPaths) ? ($analyzerPaths[$configKey] ?? null) : null;
        if (is_array($custom) && $custom !== []) {
            foreach ($custom as $p) {
                if (is_string($p) && $p !== '') {
                    $primary[] = $p;
                }
            }
        } else {
            if (($deadcode['auto_discover'] ?? true) === true) {
                $primary = [...$primary, ...ProjectStructureScanner::pathsForAnalyzer($configKey)];
            }

            if (is_callable([$resolvedClass, 'defaultScanPaths'])) {
                $defaults = $resolvedClass::defaultScanPaths();
                if (is_array($defaults) && $defaults !== []) {
                    $primary = [...$primary, ...$defaults];
                }
            }
        }

        $primary = ProjectStructureScanner::dedupePaths($primary);

        return ProjectStructureScanner::dedupePaths([...$primary, ...$globalScanPaths]);
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private static function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $p) {
            if (is_string($p) && $p !== '') {
                $out[] = $p;
            }
        }

        return $out;
    }
}
