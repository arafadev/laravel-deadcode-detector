<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Illuminate\Contracts\Foundation\Application;

/**
 * User-controlled suppression of findings after analysis. Does not change the dependency graph —
 * ignored code stays in the scan so references remain visible to other rules.
 *
 * Inline marker (file-wide): place {@see DeadcodeResultIgnoreFilter::INLINE_TAG} in a line or
 * block comment at the top of the file (Blade: <code>{{-- @deadcode-ignore --}}</code>).
 */
final class DeadcodeResultIgnoreFilter
{
    public const INLINE_TAG = '@deadcode-ignore';

    /** @var array<string, true> normalized class names (lowercase, no leading backslash) */
    private array $ignoredClasses = [];

    /** @var list<string> normalized absolute directory/file prefixes (no trailing slash, lowercase) */
    private array $folderPrefixes = [];

    /** @var list<string> fnmatch patterns, lowercase absolute paths */
    private array $fnmatchPatterns = [];

    private string $projectRootNorm;

    /** @var array<string, bool> realpath or normalized path => has inline tag */
    private array $inlineCache = [];

    /**
     * @param list<mixed> $classes  FQCN strings, e.g. App\Http\Controllers\FooController
     * @param list<mixed> $folders  Relative to project root or absolute directory paths
     * @param list<mixed> $patterns fnmatch patterns (relative to project root or absolute)
     */
    private function __construct(
        string $projectRoot,
        array $classes,
        array $folders,
        array $patterns,
    ) {
        $rootReal = realpath($projectRoot);

        $this->projectRootNorm = self::normalizePath($rootReal !== false ? $rootReal : $projectRoot);
        $this->registerClasses($classes);
        $this->registerFolders($projectRoot, $folders);
        $this->registerPatterns($patterns);
    }

    public static function fromApplication(Application $app): self
    {
        $cfg    = $app['config']->get('deadcode', []);
        $ignore = is_array($cfg) ? ($cfg['ignore'] ?? []) : [];
        if (! is_array($ignore)) {
            $ignore = [];
        }

        $classes  = $ignore['classes'] ?? [];
        $folders  = $ignore['folders'] ?? [];
        $patterns = $ignore['patterns'] ?? [];

        $root = function_exists('base_path') ? base_path() : $app->basePath();

        return self::create(
            $root,
            is_array($classes) ? $classes : [],
            is_array($folders) ? $folders : [],
            is_array($patterns) ? $patterns : [],
        );
    }

    /**
     * @param list<mixed> $classes
     * @param list<mixed> $folders
     * @param list<mixed> $patterns
     */
    public static function create(
        string $projectRoot,
        array $classes = [],
        array $folders = [],
        array $patterns = [],
    ): self {
        return new self($projectRoot, $classes, $folders, $patterns);
    }

    /**
     * @param list<DeadCodeResult> $results
     *
     * @return list<DeadCodeResult>
     */
    public function filterResults(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            if (! $this->shouldIgnore($r)) {
                $out[] = $r;
            }
        }

        return $out;
    }

    public function shouldIgnore(DeadCodeResult $r): bool
    {
        if ($this->matchesIgnoredClass($r->className)) {
            return true;
        }

        $fileNorm = self::normalizePath(self::maybeRealpath($r->filePath));
        if ($fileNorm === '') {
            return false;
        }

        if ($this->matchesFolderPrefix($fileNorm)) {
            return true;
        }

        if ($this->matchesPattern($fileNorm)) {
            return true;
        }

        return $this->fileDeclaresInlineIgnore($r->filePath);
    }

    private function matchesIgnoredClass(?string $className): bool
    {
        if ($className === null || $className === '' || $this->ignoredClasses === []) {
            return false;
        }

        $key = self::normalizeClassKey($className);

        return isset($this->ignoredClasses[$key]);
    }

    private function matchesFolderPrefix(string $fileNorm): bool
    {
        foreach ($this->folderPrefixes as $prefix) {
            if ($fileNorm === $prefix || str_starts_with($fileNorm, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function matchesPattern(string $fileNorm): bool
    {
        foreach ($this->fnmatchPatterns as $pattern) {
            if (fnmatch($pattern, $fileNorm, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }

    private function fileDeclaresInlineIgnore(string $absolutePath): bool
    {
        $cacheKey = self::normalizePath(self::maybeRealpath($absolutePath));
        if ($cacheKey === '') {
            $cacheKey = self::normalizePath($absolutePath);
        }

        if (array_key_exists($cacheKey, $this->inlineCache)) {
            return $this->inlineCache[$cacheKey];
        }

        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return $this->inlineCache[$cacheKey] = false;
        }

        $raw = @file_get_contents($absolutePath, false, null, 0, 262_144);
        if ($raw === false) {
            return $this->inlineCache[$cacheKey] = false;
        }

        $isBlade = str_ends_with($cacheKey, '.blade.php');

        return $this->inlineCache[$cacheKey] = self::contentDeclaresInlineIgnore($raw, $isBlade);
    }

    /**
     * Whether raw source already contains an inline ignore marker (matches scan + insert rules).
     */
    public static function contentDeclaresInlineIgnore(string $raw, bool $isBladeFile): bool
    {
        $tag = preg_quote(self::INLINE_TAG, '/');

        if (preg_match('/^[ \t]*(\/\/|#).*' . $tag . '(?:\s|$)/m', $raw) === 1) {
            return true;
        }

        $head = substr($raw, 0, 24_576);
        if (preg_match('/\/\*[\s\S]*?' . $tag . '[\s\S]*?\*\//', $head) === 1) {
            return true;
        }

        if ($isBladeFile && preg_match('/\{\{--[\s\S]*?' . $tag . '[\s\S]*?--\}\}/', $head) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param list<mixed> $classes
     */
    private function registerClasses(array $classes): void
    {
        foreach ($classes as $c) {
            if (! is_string($c)) {
                continue;
            }
            $c = trim($c);
            if ($c === '') {
                continue;
            }
            $key                              = self::normalizeClassKey($c);
            $this->ignoredClasses[$key] = true;
        }
    }

    /**
     * @param list<mixed> $folders
     */
    private function registerFolders(string $projectRoot, array $folders): void
    {
        foreach ($folders as $raw) {
            if (! is_string($raw)) {
                continue;
            }
            $raw = trim(str_replace('\\', '/', $raw));
            if ($raw === '') {
                continue;
            }

            $abs = self::isAbsolutePath($raw)
                ? $raw
                : rtrim($projectRoot, DIRECTORY_SEPARATOR) . '/' . ltrim($raw, '/');
            $rp  = realpath($abs);
            if ($rp !== false && is_dir($rp)) {
                $this->folderPrefixes[] = rtrim(self::normalizePath($rp), '/');

                continue;
            }
            if ($rp !== false && is_file($rp)) {
                $this->folderPrefixes[] = rtrim(self::normalizePath($rp), '/');

                continue;
            }

            // Directory may not exist yet — still match as prefix on normalized path
            $this->folderPrefixes[] = rtrim(self::normalizePath($abs), '/');
        }

        $this->folderPrefixes = array_values(array_unique(array_filter($this->folderPrefixes)));
    }

    /**
     * @param list<mixed> $patterns
     */
    private function registerPatterns(array $patterns): void
    {
        foreach ($patterns as $raw) {
            if (! is_string($raw)) {
                continue;
            }
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }

            $absolutePattern = self::isAbsolutePath($raw)
                ? str_replace('\\', '/', $raw)
                : $this->projectRootNorm . '/' . ltrim(str_replace('\\', '/', $raw), '/');
            $this->fnmatchPatterns[] = strtolower($absolutePattern);
        }

        $this->fnmatchPatterns = array_values(array_unique($this->fnmatchPatterns));
    }

    private static function normalizeClassKey(string $className): string
    {
        $c = ltrim(trim(str_replace('/', '\\', $className)), '\\');

        return strtolower($c);
    }

    private static function normalizePath(string $path): string
    {
        return strtolower(str_replace('\\', '/', $path));
    }

    private static function maybeRealpath(string $path): string
    {
        $rp = realpath($path);

        return $rp !== false ? $rp : $path;
    }

    private static function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        return $path[0] === '/'
            || (strlen($path) > 2 && $path[1] === ':' && ($path[2] === '\\' || $path[2] === '/'));
    }
}
