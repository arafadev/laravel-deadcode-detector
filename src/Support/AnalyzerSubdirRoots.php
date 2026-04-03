<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

/**
 * Resolves scan roots that are more specific than the whole app directory (merged global scan_paths).
 */
final class AnalyzerSubdirRoots
{
    /**
     * @param list<string> $scanPaths
     *
     * @return list<string>
     */
    public static function exclusive(array $scanPaths, string $fallbackRelativeToApp): array
    {
        $app = function_exists('app_path') ? realpath(app_path()) : false;
        $out = [];
        foreach ($scanPaths as $d) {
            if (! is_string($d) || ! is_dir($d)) {
                continue;
            }
            $r = realpath($d);
            if ($r === false) {
                continue;
            }
            if ($app !== false && $r === $app) {
                continue;
            }
            $out[] = $r;
        }
        if ($out === [] && function_exists('app_path')) {
            $f = realpath(app_path($fallbackRelativeToApp));
            if ($f !== false) {
                $out[] = $f;
            }
        }

        return $out;
    }
}
