<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Reporters;

use Illuminate\Console\OutputStyle;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Arafa\DeadcodeDetector\Reporters\Contracts\ReporterInterface;
use Arafa\DeadcodeDetector\Support\DetectionConfidence;

class ConsoleReporter implements ReporterInterface
{
    private const REASON_MAX_TABLE = 160;

    public function __construct(
        private readonly OutputStyle $output,
        private readonly bool $compact = false,
        private readonly bool $detailed = false,
    ) {}

    /**
     * @param DeadCodeResult[] $results
     */
    public function report(array $results): void
    {
        if ($results === []) {
            $this->output->writeln('');
            $this->output->writeln('  <fg=green>No detailed findings to list — scan summary above shows 0 issues.</>');
            $this->output->writeln('');

            return;
        }

        $grouped = [];
        foreach ($results as $result) {
            $grouped[$result->type][] = $result;
        }

        $this->output->writeln('');
        $this->output->writeln(sprintf(
            '  <fg=yellow>Findings:</> <fg=red;options=bold>%d</> <fg=gray>·</> <fg=cyan>%d</> <fg=gray>categories</>',
            count($results),
            count($grouped)
        ));
        $this->output->writeln('');

        if ($this->compact) {
            $this->writeCompact($grouped);
        } elseif ($this->detailed) {
            $this->writeDetailedTables($grouped);
        } else {
            $this->writeSimpleTables($grouped);
        }

        $this->output->writeln('  <fg=gray>More:</> <fg=white>--details</> <fg=gray>wider table ·</> <fg=white>--output=…</> <fg=gray>full text ·</> <fg=white>--format=json</>');
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
                $this->output->writeln(sprintf(
                    '    <fg=red>•</> <fg=white>%s</>  <fg=gray>|</> <fg=cyan>%s</>  <fg=gray>|</> <fg=yellow>%s</>',
                    $this->shortenPath($r->filePath),
                    $type,
                    $this->locationCell($r)
                ));
            }

            $this->output->writeln('');
        }
    }

    /**
     * Default terminal layout: three narrow columns (readable on small windows).
     *
     * @param array<string, DeadCodeResult[]> $grouped
     */
    private function writeSimpleTables(array $grouped): void
    {
        foreach ($grouped as $type => $items) {
            $label = $this->typeLabel($type);
            $this->output->writeln(sprintf(
                '  <options=bold;fg=cyan>%s</> <fg=gray>(%d)</>',
                $label,
                count($items)
            ));

            $rows = array_map(fn (DeadCodeResult $r) => [
                '<fg=cyan>' . $type . '</>',
                '<fg=white>' . $this->shortenPath($r->filePath) . '</>',
                '<fg=yellow>' . $this->locationCell($r) . '</>',
            ], $items);

            $this->output->table(['Type', 'File', 'Location'], $rows);
            $this->output->writeln('');
        }
    }

    /**
     * Wider table for `--details` (still no “safe to delete” column — it breaks narrow terminals).
     *
     * @param array<string, DeadCodeResult[]> $grouped
     */
    private function writeDetailedTables(array $grouped): void
    {
        foreach ($grouped as $type => $items) {
            $label = $this->typeLabel($type);
            $this->output->writeln(sprintf(
                '  <options=bold;fg=cyan>%s</> <fg=gray>(%d)</>',
                $label,
                count($items)
            ));

            $rows = array_map(fn (DeadCodeResult $r) => [
                '<fg=red>NOT USED</>',
                '<fg=white>' . $this->shortenPath($r->filePath) . '</>',
                $r->className ? '<fg=cyan>' . class_basename($r->className) . '</>' : '<fg=gray>—</>',
                $r->methodName ? '<fg=yellow>' . $r->methodName . '</>' : '<fg=gray>—</>',
                '<fg=gray>' . $r->lastModified . '</>',
                $this->confidenceToken($r->confidenceLevel),
                '<fg=gray>' . $this->shortenReason((string) ($r->reason ?? ''), self::REASON_MAX_TABLE) . '</>',
            ], $items);

            $this->output->table(
                ['Status', 'File', 'Class', 'Method / symbol', 'Modified', 'Confidence', 'Why (short)'],
                $rows
            );

            $this->output->writeln('');
        }
    }

    private function locationCell(DeadCodeResult $r): string
    {
        $class = $r->className ? class_basename($r->className) : '—';
        $m     = $r->methodName;

        return ($m !== null && $m !== '') ? $class . '::' . $m : $class;
    }

    private function confidenceToken(string $level): string
    {
        return match ($level) {
            DetectionConfidence::HIGH => '<fg=cyan;options=bold>HIGH</>',
            DetectionConfidence::MEDIUM => '<fg=yellow;options=bold>MED</>',
            DetectionConfidence::LOW => '<fg=white;options=bold>LOW</>',
            default => '<fg=gray>' . $level . '</>',
        };
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
            'event'             => 'EVENT (unused / missing)',
            'listener'          => 'LISTENER (unused / missing)',
            'binding'           => 'CONTAINER BINDING (issue)',
            'job'               => 'JOB (never dispatched)',
            'observer'          => 'OBSERVER (not registered / missing)',
            'request'           => 'FORM REQUEST (not type-hinted)',
            'resource'          => 'API RESOURCE (not used)',
            'policy'            => 'POLICY (not used)',
            'action'            => 'ACTION (not used)',
            'service'           => 'SERVICE (not used)',
            'command'           => 'ARTISAN COMMAND (not registered)',
            'notification'      => 'NOTIFICATION (not sent)',
            'mailable'          => 'MAILABLE (not sent)',
            'rule'              => 'VALIDATION RULE (not used)',
            'enum'              => 'ENUM (not referenced)',
            default             => strtoupper($type),
        };
    }

    private function shortenPath(string $path): string
    {
        $base = function_exists('base_path') ? base_path() : '';
        if ($base !== '' && str_starts_with($path, $base)) {
            $relative = substr($path, strlen($base) + 1);
        } else {
            $relative = $path;
        }

        if (strlen($relative) > 50) {
            return '…' . substr($relative, -49);
        }

        return $relative;
    }

    private function shortenReason(string $reason, int $maxLen): string
    {
        $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? $reason);
        if ($reason === '') {
            return '';
        }
        if (strlen($reason) > $maxLen) {
            return substr($reason, 0, $maxLen - 1) . '…';
        }

        return $reason;
    }
}
