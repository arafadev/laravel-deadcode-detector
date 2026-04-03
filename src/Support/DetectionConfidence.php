<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

/**
 * Normalizes confidence scores for reporting. Levels describe how much static analysis
 * supports the finding — not "how safe to delete" (always verify).
 */
final class DetectionConfidence
{
    public const HIGH = 'high';

    public const MEDIUM = 'medium';

    public const LOW = 'low';

    /**
     * @param 'high'|'medium'|'low'|null $explicit From analyzer when it has a strong/partial signal
     *
     * @return 'high'|'medium'|'low'
     */
    public static function normalize(?string $explicit, string $filePath, bool $possibleDynamicHint): string
    {
        if ($possibleDynamicHint) {
            return self::LOW;
        }

        $level = is_string($explicit) && in_array($explicit, [self::HIGH, self::MEDIUM, self::LOW], true)
            ? $explicit
            : null;

        if ($level === self::HIGH && self::dynamicReferenceLikelyPath($filePath)) {
            return self::MEDIUM;
        }

        if ($level !== null) {
            return $level;
        }

        if (self::dynamicReferenceLikelyPath($filePath)) {
            return self::LOW;
        }

        return self::MEDIUM;
    }

    /**
     * Short line for JSON/console legends.
     */
    public static function hintForLevel(string $level): string
    {
        return match ($level) {
            self::HIGH => 'Several static checks agree; dynamic or runtime references are still possible.',
            self::MEDIUM => 'Typical heuristic finding under the current scan roots; confirm with usage and tests.',
            self::LOW => 'Likely missed by static analysis (strings, container, reflection, or non-scanned paths).',
            default => 'Unknown confidence level.',
        };
    }

    /** One-line hint for compact CLI summaries. */
    public static function shortHintForLevel(string $level): string
    {
        return match ($level) {
            self::HIGH => 'Stronger static agreement — still verify.',
            self::MEDIUM => 'Typical heuristic — confirm with usage.',
            self::LOW => 'Often missed dynamically — manual check.',
            default => '',
        };
    }

    /**
     * Paths where class names and views are often built dynamically.
     */
    private static function dynamicReferenceLikelyPath(string $filePath): bool
    {
        $n = strtolower(str_replace('\\', '/', $filePath));

        return str_contains($n, '/routes/')
            || str_contains($n, '/config/')
            || str_contains($n, '/bootstrap/')
            || str_contains($n, 'serviceprovider')
            || str_contains($n, '/database/seeders/')
            || str_contains($n, '/database/factories/');
    }
}
