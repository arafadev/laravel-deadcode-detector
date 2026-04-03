<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use SplFileInfo;
use Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Arafa\DeadcodeDetector\Support\DependencyGraphEngine;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;
use Arafa\DeadcodeDetector\Support\PathExcludeMatcher;

class ViewsAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly PhpFileScanner $scanner,
        private readonly array $scanPaths,
        private readonly PathExcludeMatcher $pathExclude,
    ) {}

    /**
     * @return list<string>
     */
    public static function defaultScanPaths(): array
    {
        $paths = [];
        if (function_exists('app_path')) {
            $paths[] = app_path();
        }
        if (function_exists('resource_path')) {
            $paths[] = resource_path('views');
        }

        return $paths;
    }

    public function getName(): string
    {
        return 'views';
    }

    public function getDescription(): string
    {
        return 'Finds Blade view files that are never rendered or included anywhere.';
    }

    public function analyze(): array
    {
        $viewFiles = $this->findViewFiles();

        if ($viewFiles === []) {
            return [];
        }

        $graph             = DependencyGraphEngine::getOrBuild($this->scanner, $this->scanPaths, $this->pathExclude);
        $referencedDots    = $graph->reachableViewDots();
        $dynamicPrefixes   = $graph->dynamicViewPrefixes();

        $results = [];

        foreach ($viewFiles as $file) {
            $dotName = $this->toDotNotation($file);

            if (! isset($referencedDots[$dotName])) {
                $realPath = $file->getRealPath();
                if ($realPath === false) {
                    continue;
                }
                $underDynamicPrefix = false;
                foreach ($dynamicPrefixes as $prefix) {
                    if ($dotName === $prefix || str_starts_with($dotName, $prefix . '.')) {
                        $underDynamicPrefix = true;
                        break;
                    }
                }

                $payload = [
                    'analyzerName'   => $this->getName(),
                    'type'           => 'view',
                    'filePath'       => $realPath,
                    'className'      => null,
                    'methodName'     => null,
                    'lastModified'   => date('Y-m-d H:i:s', $file->getMTime()),
                    'isSafeToDelete' => false,
                ];
                if ($underDynamicPrefix) {
                    $payload['confidenceLevel']    = 'low';
                    $payload['possibleDynamicHint'] = true;
                }

                $results[] = DeadCodeResult::fromArray($payload);
            }
        }

        return $results;
    }

    /** @return SplFileInfo[] */
    private function findViewFiles(): array
    {
        $found     = [];
        $viewsPath = resource_path('views');

        if (! is_dir($viewsPath)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($viewsPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $real = $file->getRealPath();
            if ($real !== false && ! $this->isExcluded($real)) {
                $found[] = $file;
            }
        }

        return $found;
    }

    /**
     * resources/views/auth/login.blade.php → auth.login
     */
    private function toDotNotation(SplFileInfo $file): string
    {
        $base = realpath(resource_path('views'));
        if ($base === false) {
            return '';
        }

        $base .= DIRECTORY_SEPARATOR;
        $relative = str_replace($base, '', $file->getRealPath() ?: '');
        $relative = str_replace(['/', '\\'], '.', $relative);

        return (string) preg_replace('/\.blade\.php$/', '', $relative);
    }

    private function isExcluded(string $path): bool
    {
        return $this->pathExclude->shouldExclude($path);
    }
}
