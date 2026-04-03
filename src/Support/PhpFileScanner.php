<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PhpFileScanner
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Scan a single directory recursively and return only .php SplFileInfo objects.
     * Non-existent or unreadable directories are skipped with a warning.
     *
     * @return SplFileInfo[]
     */
    public function scanDirectory(string $path): array
    {
        if (! is_dir($path)) {
            $this->logger->warning(
                '[DeadcodeDetector] Directory does not exist or is not readable, skipping.',
                ['path' => $path]
            );

            return [];
        }

        $results = [];

        $flags = RecursiveDirectoryIterator::SKIP_DOTS
               | RecursiveDirectoryIterator::FOLLOW_SYMLINKS;

        $directoryIterator = new RecursiveDirectoryIterator($path, $flags);
        $iterator          = new RecursiveIteratorIterator(
            $directoryIterator,
            RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            // We intentionally do NOT load file contents here for performance.
            $results[] = $file;
        }

        return $results;
    }

    /**
     * Scan multiple directories and merge results into a single flat array.
     * Duplicate real paths are de-duplicated automatically.
     *
     * @param  string[]      $paths
     * @return SplFileInfo[]
     */
    public function scanMultiple(array $paths): array
    {
        $seen    = [];
        $results = [];

        foreach ($paths as $path) {
            foreach ($this->scanDirectory($path) as $file) {
                $realPath = $file->getRealPath();

                if ($realPath === false || isset($seen[$realPath])) {
                    continue;
                }

                $seen[$realPath] = true;
                $results[]       = $file;
            }
        }

        return $results;
    }
}
