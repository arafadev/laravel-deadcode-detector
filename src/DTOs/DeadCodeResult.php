<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\DTOs;

final class DeadCodeResult
{
    public function __construct(
        /** Name of the analyzer that discovered this dead code */
        public readonly string $analyzerName,

        /** Category: controller / model / view / route / middleware / migration */
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
    ) {}

    /**
     * Convenience factory so callers don't have to remember argument order.
     *
     * @param array{
     *   analyzerName: string,
     *   type: string,
     *   filePath: string,
     *   className?: string|null,
     *   methodName?: string|null,
     *   lastModified?: string,
     *   isSafeToDelete?: bool,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            analyzerName: $data['analyzerName'],
            type: $data['type'],
            filePath: $data['filePath'],
            className: $data['className'] ?? null,
            methodName: $data['methodName'] ?? null,
            lastModified: $data['lastModified'] ?? date('c', filemtime($data['filePath']) ?: time()),
            isSafeToDelete: $data['isSafeToDelete'] ?? false,
        );
    }

    /** Serialise to a plain array (useful for JSON reporters). */
    public function toArray(): array
    {
        return [
            'analyzer_name'     => $this->analyzerName,
            'type'              => $this->type,
            'file_path'         => $this->filePath,
            'class_name'        => $this->className,
            'method_name'       => $this->methodName,
            'last_modified'     => $this->lastModified,
            'is_safe_to_delete' => $this->isSafeToDelete,
        ];
    }
}
