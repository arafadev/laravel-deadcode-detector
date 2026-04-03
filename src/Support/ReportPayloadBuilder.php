<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;

/**
 * Canonical scan report document for JSON export and stdout. Same finding shape as {@see DeadCodeResult::toArray()}.
 */
final class ReportPayloadBuilder
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param list<DeadCodeResult> $results
     *
     * @return array{
     *     schema_version: int,
     *     generated_at: string,
     *     summary: array<string, mixed>,
     *     findings: list<array<string, mixed>>,
     *     by_type: array<string, list<array<string, mixed>>>
     * }
     */
    public static function build(array $results, ?int $phpFilesInScope = null): array
    {
        $byType = [];
        foreach ($results as $r) {
            $byType[$r->type][] = $r->toArray();
        }

        $byConfidence = [
            DetectionConfidence::HIGH    => 0,
            DetectionConfidence::MEDIUM => 0,
            DetectionConfidence::LOW    => 0,
        ];
        foreach ($results as $r) {
            if (isset($byConfidence[$r->confidenceLevel])) {
                ++$byConfidence[$r->confidenceLevel];
            }
        }

        $categoryCounts = array_map(static fn (array $items): int => count($items), $byType);

        $generatedAt = function_exists('now') ? now()->toIso8601String() : gmdate('c');

        $summary = [
            'total_findings'    => count($results),
            'by_type'           => $categoryCounts,
            'by_confidence'     => $byConfidence,
            'confidence_legend' => [
                DetectionConfidence::HIGH => DetectionConfidence::hintForLevel(DetectionConfidence::HIGH),
                DetectionConfidence::MEDIUM => DetectionConfidence::hintForLevel(DetectionConfidence::MEDIUM),
                DetectionConfidence::LOW => DetectionConfidence::hintForLevel(DetectionConfidence::LOW),
            ],
        ];
        if ($phpFilesInScope !== null) {
            $summary['php_files_in_scope'] = $phpFilesInScope;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at'   => $generatedAt,
            'summary'        => $summary,
            /** Ordered list; each row matches {@see DeadCodeResult::toArray()} (includes fix_suggestions). */
            'findings' => array_map(static fn (DeadCodeResult $r) => $r->toArray(), $results),
            'by_type'  => $byType,
        ];
    }

    /**
     * @param list<DeadCodeResult> $results
     */
    public static function toJson(array $results, ?int $phpFilesInScope = null, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): string
    {
        $json = json_encode(self::build($results, $phpFilesInScope), $flags);

        return $json !== false ? $json : '{}';
    }

    /**
     * @param list<DeadCodeResult> $results
     */
    public static function writeJsonFile(string $absolutePath, array $results, ?int $phpFilesInScope = null): void
    {
        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $bytes = self::toJson($results, $phpFilesInScope);
        if (@file_put_contents($absolutePath, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write JSON report to: ' . $absolutePath);
        }
    }

    public static function isJsonExportPath(string $path): bool
    {
        return str_ends_with(strtolower($path), '.json');
    }
}
