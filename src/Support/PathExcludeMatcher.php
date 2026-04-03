<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use Illuminate\Contracts\Foundation\Application;

/**
 * Smart path exclusion: built-in noisy roots (vendor, storage, …), optional user
 * paths, and wildcard patterns (fnmatch). Use with directory iterators for early pruning.
 */
final class PathExcludeMatcher
{
    /** @var list<string> normalized absolute directory prefixes (lowercase, /) */
    private array $builtinDirPrefixes = [];

    /** @var list<string> fnmatch patterns (normalized, lowercase) */
    private array $fnmatchPatterns = [];

    /** @var list<string> normalized path substrings */
    private array $substringExcludes = [];

    /**
     * @param list<mixed> $excludePaths
     */
    private function __construct(
        private readonly string $projectRootNorm,
        private readonly bool $useBuiltin,
        array $excludePaths,
    ) {
        if ($useBuiltin) {
            $this->registerBuiltinPrefixes();
        }
        $this->registerUserEntries($excludePaths);
    }

    /**
     * @param list<mixed> $excludePaths
     */
    public static function create(
        string $projectRoot,
        array $excludePaths = [],
        bool $useBuiltin = true,
    ): self {
        $rootNorm  = self::normalizePath(self::absoluteProjectRoot($projectRoot));
        /** @var list<string> $clean */
        $clean     = [];
        foreach ($excludePaths as $p) {
            if (is_string($p) && $p !== '') {
                $clean[] = $p;
            }
        }

        return new self($rootNorm, $useBuiltin, $clean);
    }

    public static function fromApplication(Application $app): self
    {
        $cfg       = $app['config']->get('deadcode', []);
        $user      = $cfg['exclude_paths'] ?? [];
        $useBuiltin = $cfg['exclude_builtin'] ?? true;
        if (! is_array($user)) {
            $user = [];
        }

        $root = function_exists('base_path') ? base_path() : $app->basePath();

        return self::create($root, $user, $useBuiltin !== false);
    }

    public function cacheKey(): string
    {
        return hash('sha256', serialize([
            $this->useBuiltin,
            $this->projectRootNorm,
            $this->builtinDirPrefixes,
            $this->fnmatchPatterns,
            $this->substringExcludes,
        ]));
    }

    public function shouldExclude(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        $norm = self::normalizePath(self::maybeRealpath($path));

        foreach ($this->builtinDirPrefixes as $prefix) {
            if ($norm === $prefix || str_starts_with($norm, $prefix . '/')) {
                return true;
            }
        }

        foreach ($this->substringExcludes as $frag) {
            if ($frag !== '' && str_contains($norm, $frag)) {
                return true;
            }
        }

        foreach ($this->fnmatchPatterns as $pattern) {
            if (fnmatch($pattern, $norm, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }

    private function registerBuiltinPrefixes(): void
    {
        if (! function_exists('base_path')) {
            return;
        }

        $rels = ['vendor', 'node_modules', 'storage', 'bootstrap/cache', '.git'];
        foreach ($rels as $rel) {
            $abs = base_path($rel);
            if (! is_string($abs) || $abs === '') {
                continue;
            }
            $rp = realpath($abs);
            $this->builtinDirPrefixes[] = self::normalizePath($rp !== false ? $rp : $abs);
        }

        $this->builtinDirPrefixes = array_values(array_unique(array_filter($this->builtinDirPrefixes)));
    }

    /**
     * @param list<mixed> $userEntries
     */
    private function registerUserEntries(array $userEntries): void
    {
        foreach ($userEntries as $raw) {
            if (! is_string($raw)) {
                continue;
            }
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }

            $hasWildcard = strpbrk(str_replace('\\', '/', $raw), '*?[]') !== false;

            if ($hasWildcard) {
                $absolutePattern = self::isAbsolutePath($raw)
                    ? str_replace('\\', '/', $raw)
                    : $this->projectRootNorm . '/' . ltrim(str_replace('\\', '/', $raw), '/');
                $this->fnmatchPatterns[] = strtolower(str_replace('\\', '/', $absolutePattern));

                continue;
            }

            if (self::isAbsolutePath($raw)) {
                $this->substringExcludes[] = self::normalizePath(self::maybeRealpath($raw));

                continue;
            }

            $joined = self::normalizePath(self::maybeRealpath($this->projectRootNorm . '/' . ltrim(str_replace('\\', '/', $raw), '/')));
            if ($joined !== '') {
                $this->substringExcludes[] = $joined;
            }
        }

        $this->substringExcludes = array_values(array_unique(array_filter($this->substringExcludes)));
    }

    private static function absoluteProjectRoot(string $projectRoot): string
    {
        $rp = realpath($projectRoot);

        return $rp !== false ? $rp : $projectRoot;
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

    private static function normalizePath(string $path): string
    {
        return strtolower(str_replace('\\', '/', $path));
    }
}
