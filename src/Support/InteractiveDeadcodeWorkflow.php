<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Illuminate\Console\Command;

/**
 * CLI prompts: delete file (confirmed), add inline ignore marker, or skip. No silent deletes.
 */
final class InteractiveDeadcodeWorkflow
{
    /** Finding types where deleting the reported file is almost never the right fix. */
    private const NO_FILE_DELETE_TYPES = ['route', 'binding'];

    /** Types where the finding may refer to only part of the file — warn before deleting the whole file. */
    private const PARTIAL_FILE_FINDING_TYPES = ['controller_method', 'model_scope', 'helper'];

    /**
     * @param list<DeadCodeResult> $results
     *
     * @return array{deleted: int, ignore_marker: int, skipped: int}
     */
    public static function run(Command $command, array $results): array
    {
        $stats = ['deleted' => 0, 'ignore_marker' => 0, 'skipped' => 0];

        if ($results === []) {
            return $stats;
        }

        if (! $command->input->isInteractive()) {
            $command->warn('  <fg=yellow>--interactive</> needs a TTY; skipping prompts.');

            return $stats;
        }

        $total = count($results);
        $command->line('');
        $command->info('  <options=bold>Interactive cleanup</> — <fg=gray>Delete</> removes the whole file after confirmations. <fg=gray>Ignore</> prepends ' . DeadcodeResultIgnoreFilter::INLINE_TAG . ' in PHP/Blade.');
        $command->line('  <fg=gray>Default:</> Skip — press Enter.');
        $command->line('');

        $index = 0;
        foreach ($results as $result) {
            ++$index;
            self::presentFinding($command, $result, $index, $total);

            $action = $command->choice(
                '  Action for this item',
                ['Delete', 'Ignore', 'Skip'],
                2
            );

            if ($action === 'Skip') {
                ++$stats['skipped'];
                $command->line('  <fg=gray>→ Skipped.</>');
                $command->line('');

                continue;
            }

            if ($action === 'Ignore') {
                $path = $result->filePath;
                $r = DeadcodeInlineIgnoreMarker::prependToFile($path);
                if ($r['ok']) {
                    ++$stats['ignore_marker'];
                    $command->info('  <fg=green>→</> ' . $r['message']);
                } else {
                    $command->warn('  <fg=yellow>→</> ' . $r['message']);
                }
                $command->line('');

                continue;
            }

            if ($action === 'Delete') {
                if (in_array($result->type, self::NO_FILE_DELETE_TYPES, true)) {
                    $command->warn('  <fg=yellow>→ Delete file is disabled for this finding type</> (' . $result->type . '). Edit the file or use Ignore / config.');
                    $command->line('');

                    continue;
                }

                if (! self::isSafeProjectFile($result->filePath)) {
                    $command->warn('  <fg=yellow>→ Path is outside the application project root or is not a deletable file.</>');
                    $command->line('');

                    continue;
                }

                if (in_array($result->type, self::PARTIAL_FILE_FINDING_TYPES, true)) {
                    $command->warn('  <fg=yellow>This finding may be only part of the file — deleting removes the entire file.</>');
                }

                if (! $command->confirm('  Delete entire file: ' . $result->filePath . ' ?', false)) {
                    $command->line('  <fg=gray>→ Delete cancelled.</>');
                    $command->line('');

                    continue;
                }

                if (! $command->confirm('  <fg=red>Permanent delete</> — this cannot be undone. Proceed?', false)) {
                    $command->line('  <fg=gray>→ Delete cancelled.</>');
                    $command->line('');

                    continue;
                }

                if (@unlink($result->filePath)) {
                    ++$stats['deleted'];
                    $command->info('  <fg=green>→ File deleted.</>');
                } else {
                    $command->error('  → Could not delete file (permissions or disk).');
                }
                $command->line('');
            }
        }

        if ($stats['deleted'] > 0 || $stats['ignore_marker'] > 0 || $stats['skipped'] > 0) {
            $command->line('  <fg=gray>────────────────────────────────────────</>');
            $command->line(sprintf(
                '  <fg=gray>Interactive:</> deleted <fg=white>%d</> · ignore markers <fg=white>%d</> · skipped <fg=white>%d</>',
                $stats['deleted'],
                $stats['ignore_marker'],
                $stats['skipped']
            ));
            $command->line('');
        }

        return $stats;
    }

    private static function presentFinding(Command $command, DeadCodeResult $result, int $index, int $total): void
    {
        $rel = self::relativeDisplayPath($result->filePath);
        $method = $result->methodName ?? '—';
        $class  = $result->className ?? '—';
        $command->line(sprintf(
            '  <fg=cyan>[%d / %d]</> <fg=magenta>%s</>  <fg=white>%s</>  <fg=gray>|</> class <fg=cyan>%s</>  <fg=gray>|</> <fg=yellow>%s</>  <fg=gray>|</> %s',
            $index,
            $total,
            $result->type,
            $rel,
            $class,
            $method,
            $result->confidenceLevel
        ));
        $why = trim((string) ($result->reason ?? ''));
        if ($why !== '') {
            $command->line('  <fg=gray>' . self::truncate($why, 180) . '</>');
        }
    }

    private static function truncate(string $text, int $max): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max - 1) . '…';
    }

    private static function relativeDisplayPath(string $absolutePath): string
    {
        $base = function_exists('base_path') ? realpath(base_path()) : false;
        if ($base !== false) {
            $bn = strtolower(str_replace('\\', '/', $base));
            $pn = strtolower(str_replace('\\', '/', $absolutePath));
            if ($pn === $bn || str_starts_with($pn, $bn . '/')) {
                return substr($absolutePath, strlen($base) + 1);
            }
        }

        return $absolutePath;
    }

    public static function isSafeProjectFile(string $absolutePath): bool
    {
        if (! function_exists('base_path')) {
            return false;
        }

        $base = realpath(base_path());
        if ($base === false) {
            return false;
        }

        $target = realpath($absolutePath);
        if ($target === false || ! is_file($target)) {
            return false;
        }

        $baseNorm   = strtolower(str_replace('\\', '/', $base));
        $targetNorm = strtolower(str_replace('\\', '/', $target));

        if ($targetNorm !== $baseNorm && ! str_starts_with($targetNorm, $baseNorm . '/')) {
            return false;
        }

        foreach (['vendor', 'node_modules', 'storage', 'bootstrap/cache', '.git'] as $frag) {
            if (str_contains($targetNorm, '/' . $frag . '/') || str_ends_with($targetNorm, '/' . $frag)) {
                return false;
            }
        }

        return true;
    }
}
