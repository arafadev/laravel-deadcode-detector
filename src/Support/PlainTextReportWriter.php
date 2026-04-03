<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;

/**
 * Writes every finding to a UTF-8 text file (no truncation — unlike terminal scrollback).
 */
final class PlainTextReportWriter
{
    /**
     * @param list<DeadCodeResult> $results
     */
    public static function write(string $absolutePath, array $results): void
    {
        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $lines   = [];
        $lines[] = 'Laravel Dead Code Detector — full report';
        $lines[] = 'Generated: ' . gmdate('c');
        $lines[] = 'Total: ' . count($results) . ' item(s)';
        $lines[] = str_repeat('=', 80);
        $lines[] = '';

        $grouped = [];
        foreach ($results as $r) {
            $grouped[$r->type][] = $r;
        }

        ksort($grouped);

        foreach ($grouped as $type => $items) {
            $lines[] = '[' . strtoupper($type) . '] — ' . count($items) . ' item(s)';
            $lines[] = str_repeat('-', 80);

            foreach ($items as $r) {
                $reason = $r->reason !== null && $r->reason !== ''
                    ? $r->reason
                    : '(no reason — check analyzer configuration)';
                $lines[] = 'NOT USED';
                $lines[] = '  file_path        : ' . $r->filePath;
                $lines[] = '  type             : ' . $r->type;
                $lines[] = '  reason           : ' . $reason;
                $lines[] = '  confidence_level : ' . $r->confidenceLevel;
                $lines[] = '  class_name       : ' . ($r->className ?? '(none)');
                $lines[] = '  method_name      : ' . ($r->methodName ?? '(none)');
                $lines[] = '  analyzer_name    : ' . $r->analyzerName;
                $lines[] = '  last_modified    : ' . $r->lastModified;
                $lines[] = '  is_safe_to_delete: ' . ($r->isSafeToDelete ? 'yes' : 'no');
                $lines[] = '  context_hint     : ' . FindingFixSuggestion::contextHint($r);
                $lines[] = '  next_steps_line  : ' . FindingFixSuggestion::actionsSummaryLine($r);
                $lines[] = '  suggested_actions:';
                foreach (FindingFixSuggestion::suggestedActions($r) as $step) {
                    $lines[] = sprintf(
                        '    - [%s] %s — %s',
                        $step['action'],
                        $step['label'],
                        $step['detail']
                    );
                }
                $lines[] = '';
            }
        }

        if ($results === []) {
            $lines[] = '(No dead-code items reported.)';
        }

        file_put_contents($absolutePath, implode("\n", $lines), LOCK_EX);
    }
}
