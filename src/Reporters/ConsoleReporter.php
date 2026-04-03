<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Reporters;

use Illuminate\Console\OutputStyle;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Arafa\DeadcodeDetector\Reporters\Contracts\ReporterInterface;

class ConsoleReporter implements ReporterInterface
{
    public function __construct(
        private readonly OutputStyle $output,
        private readonly bool $compact = false,
    ) {}

    /**
     * @param DeadCodeResult[] $results
     */
    public function report(array $results): void
    {
        if ($results === []) {
            $this->output->writeln('');
            $this->output->writeln('  <fg=green>✅  No dead code detected. Your codebase is clean!</>');
            $this->output->writeln('');

            return;
        }

        $grouped = [];
        foreach ($results as $result) {
            $grouped[$result->type][] = $result;
        }

        $this->output->writeln('');
        $this->output->writeln(sprintf(
            '  <fg=yellow>⚠️  Found <fg=red;options=bold>%d</> NOT USED item(s)</> across <fg=cyan>%d</> categories.',
            count($results),
            count($grouped)
        ));
        $this->output->writeln('');

        if ($this->compact) {
            $this->writeCompact($grouped);
        } else {
            $this->writeTables($grouped);
        }

        $this->output->writeln('  <fg=gray>Tip:</> long lists are often <fg=yellow>cut off</> in the terminal (scrollback limit).');
        $this->output->writeln('  <fg=gray>Use</> <fg=white>--output=storage/app/deadcode-full.txt</> <fg=gray>(or any path) for the complete plain-text report.</>');
        $this->output->writeln('');
    }

    /**
     * @param array<string, DeadCodeResult[]> $grouped
     */
    private function writeCompact(array $grouped): void
    {
        foreach ($grouped as $type => $items) {
            $label = $this->typeLabel($type);
            $this->output->writeln(sprintf('  <options=bold;fg=cyan>%s</> <fg=gray>(%d)</>', $label, count($items)));

            foreach ($items as $r) {
                $class  = $r->className ? class_basename($r->className) : '—';
                $method = $r->methodName ?? '—';
                $path   = $this->shortenPath($r->filePath);
                $this->output->writeln(sprintf(
                    '    <fg=red>•</> <fg=white>%s</>  <fg=gray>|</> %s  <fg=gray>|</> <fg=yellow>%s</>',
                    $path,
                    $class,
                    $method
                ));
            }

            $this->output->writeln('');
        }
    }

    /**
     * @param array<string, DeadCodeResult[]> $grouped
     */
    private function writeTables(array $grouped): void
    {
        foreach ($grouped as $type => $items) {
            $label = $this->typeLabel($type);
            $this->output->writeln(sprintf(
                '  <options=bold;fg=cyan>%s</> <fg=gray>(%d item%s)</>',
                $label,
                count($items),
                count($items) === 1 ? '' : 's'
            ));
            $this->output->writeln(str_repeat('─', 80));

            $rows = array_map(fn (DeadCodeResult $r) => [
                '<fg=red>NOT USED</>',
                '<fg=white>' . $this->shortenPath($r->filePath) . '</>',
                $r->className ? '<fg=cyan>' . class_basename($r->className) . '</>' : '<fg=gray>—</>',
                $r->methodName ? '<fg=yellow>' . $r->methodName . '</>' : '<fg=gray>—</>',
                '<fg=gray>' . $r->lastModified . '</>',
                $r->isSafeToDelete
                    ? '<fg=green>✓ Yes</>'
                    : '<fg=red>✗ No</>',
            ], $items);

            $this->output->table(
                ['Status', 'File', 'Class', 'Method / Helper / Route', 'Last Modified', 'Safe to Delete'],
                $rows
            );

            $this->output->writeln('');
        }
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'controller'        => 'CONTROLLER (file not used)',
            'controller_method' => 'CONTROLLER METHOD (not routed)',
            'model'             => 'MODEL (class not used)',
            'model_scope'       => 'MODEL SCOPE (not called)',
            'view'              => 'VIEW (not referenced)',
            'helper'            => 'HELPER FUNCTION (not called)',
            'route'             => 'ROUTE NAME (not referenced)',
            'middleware'        => 'MIDDLEWARE (not used)',
            'migration'         => 'MIGRATION (issue)',
            default             => strtoupper($type),
        };
    }

    private function shortenPath(string $path): string
    {
        $base = base_path();
        $relative = str_replace($base . DIRECTORY_SEPARATOR, '', $path);
        if (strlen($relative) > 50) {
            return '…' . substr($relative, -49);
        }

        return $relative;
    }
}
