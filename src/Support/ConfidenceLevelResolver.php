<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

/**
 * @deprecated Use {@see DetectionConfidence::normalize()}. Kept for any external callers of {@see resolve()}.
 */
final class ConfidenceLevelResolver
{
    /**
     * Historical helper (previously mixed file age into confidence, which mis-stated signal strength).
     *
     * @return 'high'|'medium'|'low'
     */
    public static function resolve(string $filePath, int $mtime): string
    {
        unset($mtime);

        return DetectionConfidence::normalize(null, $filePath, false);
    }
}
