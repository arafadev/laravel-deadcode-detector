<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

/**
 * Blade @extends / @include / @component edges for view reachability (template dependency graph).
 */
final class BladeOutgoingLinkExtractor
{
    /**
     * @return list<string> dot-notation view names referenced from Blade source
     */
    public static function extractOutgoingDotNamesFromContent(string $content): array
    {
        $out      = [];
        $patterns = [
            '/@extends\s*\(\s*[\'"]([^\'"]+)[\'"]/',
            '/@includeWhen\s*\(\s*[^,]+,\s*[\'"]([^\'"]+)[\'"]/',
            '/@includeUnless\s*\(\s*[^,]+,\s*[\'"]([^\'"]+)[\'"]/',
            '/@includeFirst\s*\(\s*\[[^\]]*\],\s*[\'"]([^\'"]+)[\'"]/',
            '/@include(?:If|When|Unless|First)?\s*\(\s*[\'"]([^\'"]+)[\'"]/',
            '/@component(?:First)?\s*\(\s*[\'"]([^\'"]+)[\'"]/',
            '/@each\s*\(\s*[\'"]([^\'"]+)[\'"]/',
            '/\bview\s*\(\s*[\'"]([^\'"]+)[\'"]/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $m)) {
                foreach ($m[1] as $name) {
                    $n = self::normalizeDotName(trim((string) $name));
                    if ($n !== '') {
                        $out[] = $n;
                    }
                }
            }
        }

        return $out;
    }

    public static function normalizeDotName(string $name): string
    {
        return str_replace('/', '.', str_replace('\\', '/', $name));
    }
}
