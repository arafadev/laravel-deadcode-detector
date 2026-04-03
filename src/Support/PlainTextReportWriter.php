<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;

/**
 * Writes every finding to a UTF-8 plain-text file (human-friendly layout + emoji section cues).
 */
final class PlainTextReportWriter
{
    private const LINE_WIDTH = 78;

    /**
     * @param list<DeadCodeResult> $results
     */
    public static function write(string $absolutePath, array $results): void
    {
        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $lines = [];

        $lines[] = '╔' . str_repeat('═', self::LINE_WIDTH - 2) . '╗';
        $lines[] = self::centerLine('📋  Laravel Dead Code Detector', ' ', self::LINE_WIDTH);
        $lines[] = self::centerLine('   Full report (plain text)', ' ', self::LINE_WIDTH);
        $lines[] = '╚' . str_repeat('═', self::LINE_WIDTH - 2) . '╝';
        $lines[] = '';
        $lines[] = '🕐  Generated (UTC): ' . gmdate('Y-m-d H:i:s') . '  ·  ISO: ' . gmdate('c');
        $lines[] = '';

        $total = count($results);
        $lines[] = '📊  Total findings: ' . $total;
        $lines[] = '';

        if ($total > 0) {
            $byConf = [
                DetectionConfidence::HIGH    => 0,
                DetectionConfidence::MEDIUM => 0,
                DetectionConfidence::LOW    => 0,
            ];
            foreach ($results as $r) {
                if (isset($byConf[$r->confidenceLevel])) {
                    ++$byConf[$r->confidenceLevel];
                }
            }
            $lines[] = '   By confidence:';
            $lines[] = '      🔴 High:    ' . $byConf[DetectionConfidence::HIGH];
            $lines[] = '      🟡 Medium:  ' . $byConf[DetectionConfidence::MEDIUM];
            $lines[] = '      🔵 Low:     ' . $byConf[DetectionConfidence::LOW];
            $lines[] = '';
        }

        $lines[] = str_repeat('─', self::LINE_WIDTH);
        $lines[] = '';

        $grouped = [];
        foreach ($results as $r) {
            $grouped[$r->type][] = $r;
        }

        ksort($grouped);

        $findingNo = 0;
        foreach ($grouped as $type => $items) {
            $emoji = self::typeEmoji($type);
            $lines[] = $emoji . '  ' . strtoupper($type) . '  —  ' . count($items) . ' item(s)';
            $lines[] = str_repeat('·', self::LINE_WIDTH);
            $lines[] = '';

            foreach ($items as $r) {
                ++$findingNo;
                $lines[] = self::confidenceBanner($r->confidenceLevel) . '  Finding #' . $findingNo;
                $lines[] = '┌' . str_repeat('─', self::LINE_WIDTH - 2) . '┐';

                $reason = $r->reason !== null && $r->reason !== ''
                    ? $r->reason
                    : '(no reason — check analyzer configuration)';

                $rows = [
                    ['🔖 Status', 'NOT USED (possible dead / unused code)'],
                    ['📁 File', $r->filePath],
                    ['🏷️  Type', $r->type],
                    ['🧭 Analyzer', $r->analyzerName],
                    ['📛 Class', $r->className ?? '—'],
                    ['⚡ Method / symbol', $r->methodName ?? '—'],
                    ['🎯 Confidence', $r->confidenceLevel . '  (' . DetectionConfidence::shortHintForLevel($r->confidenceLevel) . ')'],
                    ['🗑️  Safe to delete (flag)', $r->isSafeToDelete ? 'yes (still verify manually)' : 'no'],
                    ['📝 Last modified', $r->lastModified],
                ];

                foreach ($rows as [$label, $value]) {
                    foreach (self::wrapField($label, $value) as $rowLine) {
                        $lines[] = '│ ' . $rowLine;
                    }
                }

                $lines[] = '│';
                $lines[] = '│ 💬 Why';
                foreach (self::wrapParagraph($reason, self::LINE_WIDTH - 6) as $p) {
                    $lines[] = '│    ' . $p;
                }

                $lines[] = '│';
                $lines[] = '│ 💡 Context';
                foreach (self::wrapParagraph(FindingFixSuggestion::contextHint($r), self::LINE_WIDTH - 6) as $p) {
                    $lines[] = '│    ' . $p;
                }

                $lines[] = '│';
                $lines[] = '│ ➡️  Suggested next step';
                foreach (self::wrapParagraph(FindingFixSuggestion::actionsSummaryLine($r), self::LINE_WIDTH - 6) as $p) {
                    $lines[] = '│    ' . $p;
                }

                $lines[] = '│';
                $lines[] = '│ 🛠️  Actions';
                foreach (FindingFixSuggestion::suggestedActions($r) as $step) {
                    $lines[] = '│    • [' . $step['action'] . '] ' . $step['label'];
                    foreach (self::wrapParagraph($step['detail'], self::LINE_WIDTH - 10) as $p) {
                        $lines[] = '│      ' . $p;
                    }
                }

                $lines[] = '└' . str_repeat('─', self::LINE_WIDTH - 2) . '┘';
                $lines[] = '';
            }
        }

        if ($results === []) {
            $lines[] = '✨  No dead-code findings for this scan — nice and clean!';
            $lines[] = '';
        } else {
            $lines[] = str_repeat('═', self::LINE_WIDTH);
            $lines[] = '✅  End of report  ·  ' . $total . ' finding(s) above  ·  review before deleting anything';
            $lines[] = '';
        }

        file_put_contents($absolutePath, implode("\n", $lines), LOCK_EX);
    }

    private static function centerLine(string $text, string $padChar, int $width): string
    {
        $inner      = max(2, $width - 2);
        $textLen    = self::strLenUtf8($text);
        $padTotal   = max(0, $inner - $textLen);
        $left       = intdiv($padTotal, 2);
        $right      = $padTotal - $left;
        $pad        = str_repeat($padChar, max(0, $left - 1));
        $padR       = str_repeat($padChar, max(0, $right - 1));

        return '║' . $pad . ' ' . $text . ' ' . $padR . '║';
    }

    private static function strLenUtf8(string $s): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($s, 'UTF-8');
        }

        return strlen($s);
    }

    private static function confidenceBanner(string $level): string
    {
        return match ($level) {
            DetectionConfidence::HIGH => '🟥',
            DetectionConfidence::LOW => '🔷',
            default => '🟨',
        };
    }

    private static function typeEmoji(string $type): string
    {
        return match ($type) {
            'controller', 'controller_method' => '🎮',
            'model', 'model_scope' => '🗃️',
            'view' => '👁️',
            'route' => '🛣️',
            'middleware' => '🧱',
            'migration' => '📜',
            'helper' => '🔧',
            'event' => '📣',
            'listener' => '👂',
            'job' => '📬',
            'observer' => '🔭',
            'request' => '📨',
            'resource' => '📤',
            'policy' => '🛡️',
            'action' => '⚡',
            'service' => '⚙️',
            'command' => '⌨️',
            'notification' => '🔔',
            'mailable' => '✉️',
            'rule' => '✅',
            'enum' => '🔢',
            'binding' => '🧩',
            default => '📦',
        };
    }

    /**
     * @return list<string>
     */
    private static function wrapField(string $label, string $value): array
    {
        $labelW = 24;
        $first  = str_pad($label . ':', $labelW);
        $maxVal = self::LINE_WIDTH - 4 - $labelW;
        $chunks = self::wrapParagraph($value, max(20, $maxVal));
        $out    = [];
        $out[]  = $first . ($chunks[0] ?? '');
        for ($i = 1, $n = count($chunks); $i < $n; ++$i) {
            $out[] = str_repeat(' ', $labelW) . $chunks[$i];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function wrapParagraph(string $text, int $width): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return [''];
        }

        $out   = [];
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $line  = '';

        foreach ($words as $w) {
            $try = $line === '' ? $w : $line . ' ' . $w;
            if (self::strLenUtf8($try) <= $width) {
                $line = $try;
            } else {
                if ($line !== '') {
                    $out[] = $line;
                }
                $line = self::strLenUtf8($w) > $width ? self::hardBreakWord($w, $width) : $w;
                if (str_contains($line, "\n")) {
                    $parts = explode("\n", $line);
                    foreach (array_slice($parts, 0, -1) as $part) {
                        $out[] = $part;
                    }
                    $line = end($parts) ?: '';
                }
            }
        }
        if ($line !== '') {
            $out[] = $line;
        }

        return $out !== [] ? $out : [''];
    }

    private static function hardBreakWord(string $word, int $width): string
    {
        if (self::strLenUtf8($word) <= $width) {
            return $word;
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            $first = mb_substr($word, 0, $width, 'UTF-8');
            $rest  = mb_substr($word, $width, null, 'UTF-8');

            return $first . "\n" . self::hardBreakWord($rest, $width);
        }

        return substr($word, 0, $width) . "\n" . self::hardBreakWord(substr($word, $width), $width);
    }
}
