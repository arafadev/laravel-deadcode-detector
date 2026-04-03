<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\DTOs;

use Arafa\DeadcodeDetector\Support\DetectionConfidence;
use Arafa\DeadcodeDetector\Support\FindingFixSuggestion;
use Arafa\DeadcodeDetector\Support\FindingReasonCatalog;

final class DeadCodeResult
{
    public function __construct(
        /** Name of the analyzer that discovered this dead code */
        public readonly string $analyzerName,

        /** Category: controller / model / view / route / middleware / migration / … */
        public readonly string $type,

        /** Absolute path to the file */
        public readonly string $filePath,

        /** Fully-qualified class name, if applicable */
        public readonly ?string $className,

        /** Method name inside the class, if applicable */
        public readonly ?string $methodName,

        /** ISO-8601 date string of the file's last modification time */
        public readonly string $lastModified,

        /** Whether it is safe to delete without side-effects (conservative default) */
        public readonly bool $isSafeToDelete = false,

        /** high | medium | low — how strongly static analysis supports the finding (not “safe to delete”). */
        public readonly string $confidenceLevel = 'medium',

        /** Human-readable explanation of why this was flagged. Prefer {@see DeadCodeResult::fromArray()} so defaults apply. */
        public readonly ?string $reason = null,
    ) {}

    /**
     * @param array{
     *   analyzerName: string,
     *   type: string,
     *   filePath: string,
     *   className?: string|null,
     *   methodName?: string|null,
     *   lastModified?: string,
     *   isSafeToDelete?: bool,
     *   confidenceLevel?: string,
     *   reason?: string|null,
     *   dynamicHint?: bool,
     *   orphanedHint?: bool,
     *   possibleDynamicHint?: bool,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $filePath = $data['filePath'];
        $mtime    = (int) (@filemtime($filePath) ?: time());

        $possibleDynamic = ($data['possibleDynamicHint'] ?? false) === true
            || ($data['dynamicHint'] ?? false) === true;

        $requestedConfidence = isset($data['confidenceLevel']) && is_string($data['confidenceLevel'])
            ? $data['confidenceLevel']
            : null;

        $confidence = DetectionConfidence::normalize(
            $requestedConfidence,
            $filePath,
            $possibleDynamic,
        );

        $reason = $data['reason'] ?? null;
        if ($possibleDynamic && ($reason === null || trim((string) $reason) === '')) {
            $reason = 'May be referenced dynamically (string class names, the container, configuration, reflection, or code outside the scan roots) — static analysis could not prove usage.';
        }
        if ($reason === null || trim((string) $reason) === '') {
            $reason = FindingReasonCatalog::defaultExplanation(
                (string) $data['analyzerName'],
                (string) $data['type'],
            );
        }

        return new self(
            analyzerName: $data['analyzerName'],
            type: $data['type'],
            filePath: $filePath,
            className: $data['className'] ?? null,
            methodName: $data['methodName'] ?? null,
            lastModified: $data['lastModified'] ?? date('Y-m-d H:i:s', $mtime),
            isSafeToDelete: $data['isSafeToDelete'] ?? false,
            confidenceLevel: $confidence,
            reason: is_string($reason) ? $reason : null,
        );
    }

    /**
     * Canonical export shape (JSON, file export, APIs). Primary fields first for readability.
     *
     * @return array{
     *   file_path: string,
     *   type: string,
     *   reason: string,
     *   why: string,
     *   confidence_level: string,
     *   confidence_hint: string,
     *   class_name: string|null,
     *   method_name: string|null,
     *   analyzer_name: string,
     *   last_modified: string,
     *   is_safe_to_delete: bool,
     *   fix_suggestions: array{
     *     context_hint: string,
     *     actions: list<array{action: string, label: string, detail: string}>
     *   }
     * }
     */
    public function toArray(): array
    {
        $reason = $this->reason ?? '';

        return [
            'file_path'          => $this->filePath,
            'type'               => $this->type,
            'reason'             => $reason,
            'why'                => $reason,
            'confidence_level'   => $this->confidenceLevel,
            'confidence_hint'    => DetectionConfidence::hintForLevel($this->confidenceLevel),
            'class_name'         => $this->className,
            'method_name'        => $this->methodName,
            'analyzer_name'      => $this->analyzerName,
            'last_modified'      => $this->lastModified,
            'is_safe_to_delete'  => $this->isSafeToDelete,
            'fix_suggestions'    => FindingFixSuggestion::payload($this),
        ];
    }
}
