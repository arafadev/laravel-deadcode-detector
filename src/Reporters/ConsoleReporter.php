<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Reporters;

use Illuminate\Console\OutputStyle;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Arafa\DeadcodeDetector\Reporters\Contracts\ReporterInterface;
use Arafa\DeadcodeDetector\Support\DetectionConfidence;
use Arafa\DeadcodeDetector\Support\FindingFixSuggestion;

class ConsoleReporter implements ReporterInterface
{
    private const REASON_MAX_COMPACT = 96;

    private const REASON_MAX_TABLE = 160;

    private const HINT_MAX_TABLE = 72;

    private const NEXT_STEPS_MAX_TABLE = 88;

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
            '  <fg=yellow>Details:</> <fg=red;options=bold>%d</> item(s) in <fg=cyan>%d</> categories <fg=gray>(see summary above for confidence totals).</>',
            count($results),
            count($grouped)
        ));
        $this->output->writeln('  <fg=gray>Row colours:</> <fg=red>NOT USED</> finding · <fg=yellow>MED</> / <fg=white>LOW</> confidence markers in tables below.');
        $this->output->writeln('');

        if ($this->compact) {
            $this->writeCompact($grouped);
        } else {
            $this->writeTables($grouped);
        }

        $this->output->writeln('  <fg=gray>Tip:</> long lists are often <fg=yellow>cut off</> in the terminal (scrollback limit).');
        $this->output->writeln('  <fg=gray>Use</> <fg=white>--output=storage/app/deadcode-full.txt</> <fg=gray>(or any path) for the complete plain-text report.</>');
        $this->output->writeln('  <fg=gray>JSON:</> <fg=white>--format=json</> <fg=gray>includes</> <fg=white>reason</>, <fg=white>fix_suggestions</> <fg=gray>(context + safe actions).</>');
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
                $why    = $this->shortenReason((string) ($r->reason ?? ''), self::REASON_MAX_COMPACT);
                $hint   = $this->shortenReason(FindingFixSuggestion::contextHint($r), 64);
                $next   = $this->shortenReason(FindingFixSuggestion::actionsSummaryLine($r), 72);
                $reason = $why !== '' ? '  <fg=gray>' . $why . '</>' : '';
                $tail   = $hint !== '' ? sprintf('  <fg=cyan>%s</> <fg=gray>|</> %s', $hint, $next) : '';
                $this->output->writeln(sprintf(
                    '    <fg=red>•</> <fg=white>%s</>  <fg=gray>|</> %s  <fg=gray>|</> <fg=yellow>%s</>  <fg=gray>|</> %s%s%s',
                    $path,
                    $class,
                    $method,
                    $this->confidenceToken($r->confidenceLevel),
                    $reason,
                    $tail !== '' ? '  ' . $tail : ''
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
                $this->confidenceToken($r->confidenceLevel),
                '<fg=cyan>' . $this->shortenReason(FindingFixSuggestion::contextHint($r), self::HINT_MAX_TABLE) . '</>',
                '<fg=gray>' . $this->shortenReason((string) ($r->reason ?? ''), self::REASON_MAX_TABLE) . '</>',
                '<fg=green>' . $this->shortenReason(FindingFixSuggestion::actionsSummaryLine($r), self::NEXT_STEPS_MAX_TABLE) . '</>',
                $r->isSafeToDelete
                    ? '<fg=green>✓ Yes</>'
                    : '<fg=red>✗ No</>',
            ], $items);

            $this->output->table(
                ['Status', 'File', 'Class', 'Method / helper / route', 'Modified', 'Confidence', 'Context hint', 'Why (short)', 'Next steps', 'Safe delete'],
                $rows
            );

            $this->output->writeln('');
        }
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
