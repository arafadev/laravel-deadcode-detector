<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Reporters\Contracts;

interface ReporterInterface
{
    /**
     * Render / output the given results.
     *
     * @param array<\Arafa\DeadcodeDetector\DTOs\DeadCodeResult> $results
     */
    public function report(array $results): void;
}
