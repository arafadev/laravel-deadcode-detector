<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Reporters;

use Arafa\DeadcodeDetector\Reporters\Contracts\ReporterInterface;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Illuminate\Console\OutputStyle;

class JsonReporter implements ReporterInterface
{
    public function __construct(private readonly OutputStyle $output) {}

    /**
     * @param DeadCodeResult[] $results
     */
    public function report(array $results): void
    {
        // Group by type for structured output
        $grouped = [];
        foreach ($results as $result) {
            $grouped[$result->type][] = $result->toArray();
        }

        $payload = [
            'summary' => [
                'total'      => count($results),
                'categories' => array_map(fn ($items) => count($items), $grouped),
                'scanned_at' => now()->toIso8601String(),
            ],
            'results' => $grouped,
        ];

        $this->output->writeln(
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
}
