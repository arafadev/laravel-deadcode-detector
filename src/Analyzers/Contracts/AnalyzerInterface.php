<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers\Contracts;

interface AnalyzerInterface
{
    /**
     * Run the analyzer and return an array of DeadCodeResult DTOs.
     *
     * @return array<\Arafa\DeadcodeDetector\DTOs\DeadCodeResult>
     */
    public function analyze(): array;

    /**
     * Return a unique machine-readable name for this analyzer.
     * Example: "controllers", "models"
     */
    public function getName(): string;

    /**
     * Return a human-readable description of what this analyzer detects.
     */
    public function getDescription(): string;
}
