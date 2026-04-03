<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PhpFileScanner
{
    private LoggerInterface $logger;

    public function __construct(
        ?LoggerInterface $logger = null,
        private readonly ?PathExcludeMatcher $excludeMatcher = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Scan a single directory recursively and return only .php SplFileInfo objects.
     * Non-existent or unreadable directories are skipped with a warning.
     *
     * Excluded directories are pruned during traversal (no recursion into vendor, etc.).
     *
     * @return SplFileInfo[]
     */
    public function scanDirectory(string $path): array
    {
        return iterator_to_array($this->scanDirectoryLazy($path), false);
    }

    /**
     * Same as {@see scanDirectory} but streams results to keep memory flat on huge trees.
     *
     * @return \Generator<int, SplFileInfo>
     */
    public function scanDirectoryLazy(string $path): \Generator
    {
        if (! is_dir($path)) {
            $this->logger->warning(
                '[DeadcodeDetector] Directory does not exist or is not readable, skipping.',
                ['path' => $path]
            );

            return;
        }

        $flags = RecursiveDirectoryIterator::SKIP_DOTS
               | RecursiveDirectoryIterator::FOLLOW_SYMLINKS;

        $directoryIterator = new RecursiveDirectoryIterator($path, $flags);

        if ($this->excludeMatcher !== null) {
            $matcher = $this->excludeMatcher;
            $directoryIterator = new RecursiveCallbackFilterIterator(
                $directoryIterator,
                static function (\SplFileInfo $current) use ($matcher): bool {
                    return ! $matcher->shouldExclude($current->getPathname());
                }
            );
        }

        $iterator = new RecursiveIteratorIterator(
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

            yield $file;
        }
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
            foreach ($this->scanDirectoryLazy($path) as $file) {
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
