<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

/**
 * Prepends {@see DeadcodeResultIgnoreFilter::INLINE_TAG} into PHP or Blade sources for interactive "Ignore".
 */
final class DeadcodeInlineIgnoreMarker
{
    /**
     * @return array{ok: bool, message: string}
     */
    public static function prependToFile(string $absolutePath): array
    {
        if (! is_file($absolutePath)) {
            return ['ok' => false, 'message' => 'Not a file or path missing.'];
        }

        if (! is_readable($absolutePath) || ! is_writable($absolutePath)) {
            return ['ok' => false, 'message' => 'File is not readable/writable.'];
        }

        $norm = strtolower(str_replace('\\', '/', $absolutePath));
        $isBlade = str_ends_with($norm, '.blade.php');
        $isPhp = str_ends_with($norm, '.php') && ! $isBlade;

        if (! $isBlade && ! $isPhp) {
            return ['ok' => false, 'message' => 'Only .php and .blade.php files can receive an inline ignore marker.'];
        }

        $raw = file_get_contents($absolutePath);
        if ($raw === false) {
            return ['ok' => false, 'message' => 'Could not read file.'];
        }

        if (DeadcodeResultIgnoreFilter::contentDeclaresInlineIgnore($raw, $isBlade)) {
            return ['ok' => true, 'message' => 'File already contains ' . DeadcodeResultIgnoreFilter::INLINE_TAG . '.'];
        }

        $tagLine = '// ' . DeadcodeResultIgnoreFilter::INLINE_TAG;

        if ($isBlade) {
            $written = file_put_contents($absolutePath, '{{-- ' . DeadcodeResultIgnoreFilter::INLINE_TAG . " --}}\n" . $raw, LOCK_EX);
            if ($written === false) {
                return ['ok' => false, 'message' => 'Could not write file.'];
            }

            return ['ok' => true, 'message' => 'Prepended Blade ignore comment.'];
        }

        $bom = "\xEF\xBB\xBF";
        if (str_starts_with($raw, $bom)) {
            $rawNoBom = substr($raw, strlen($bom));
            $inserted = self::insertPhpIgnoreAfterOpenTag($rawNoBom, $tagLine);
            if ($inserted === null) {
                $new = $bom . "<?php\n{$tagLine}\n\n" . $rawNoBom;
            } else {
                $new = $bom . $inserted;
            }
        } else {
            $inserted = self::insertPhpIgnoreAfterOpenTag($raw, $tagLine);
            $new      = $inserted ?? "<?php\n{$tagLine}\n\n" . $raw;
        }

        if ($new === $raw) {
            return ['ok' => false, 'message' => 'Could not insert marker (unexpected file layout).'];
        }

        if (file_put_contents($absolutePath, $new, LOCK_EX) === false) {
            return ['ok' => false, 'message' => 'Could not write file.'];
        }

        return ['ok' => true, 'message' => 'Prepended ' . $tagLine];
    }

    private static function insertPhpIgnoreAfterOpenTag(string $raw, string $tagLine): ?string
    {
        if (preg_match('/^<\?php\b/', $raw) === 1) {
            $replaced = preg_replace('/^<\?php\b/', "<?php\n{$tagLine}", $raw, 1);

            return is_string($replaced) ? $replaced : null;
        }
        if (preg_match('/^<\?=\s*/', $raw) === 1) {
            $replaced = preg_replace('/^<\?=\s*/', "<?=\n{$tagLine}\n", $raw, 1);

            return is_string($replaced) ? $replaced : null;
        }

        return null;
    }
}
