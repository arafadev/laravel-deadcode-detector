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
                $lines[] = 'NOT USED';
                $lines[] = '  file_path   : ' . $r->filePath;
                $lines[] = '  class       : ' . ($r->className ?? '(none)');
                $lines[] = '  method/name : ' . ($r->methodName ?? '(none)');
                $lines[] = '  modified    : ' . $r->lastModified;
                $lines[] = '  analyzer    : ' . $r->analyzerName;
                $lines[] = '';
            }
        }

        if ($results === []) {
            $lines[] = '(No dead-code items reported.)';
        }

        file_put_contents($absolutePath, implode("\n", $lines), LOCK_EX);
    }
}
