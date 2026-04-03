<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use PhpParser\Error;
use PhpParser\Node;

/**
 * Parses PHP files with optional on-disk AST cache keyed by content hash.
 *
 * When caching is enabled, a small stat sidecar (mtime + size → last content hash) avoids
 * reading file contents for unchanged sources so repeat scans mostly hit disk cache only.
 */
final class CachedAstParser
{
    private const CACHE_VERSION = 1;

    /** Sidecar format version (bump when changing stat meta JSON shape). */
    private const STAT_META_VERSION = 1;

    /**
     * @return list<Node>|null
     */
    public static function parseFile(string $absolutePath): ?array
    {
        $real = realpath($absolutePath);
        if ($real === false || ! is_file($real)) {
            return null;
        }

        if (! self::cacheEnabled()) {
            $code = @file_get_contents($real);
            if ($code === false) {
                return null;
            }

            return self::parseCode($code);
        }

        clearstatcache(true, $real);
        $fileStat = @stat($real);
        if ($fileStat === false) {
            return null;
        }

        $mtime = (int) ($fileStat['mtime'] ?? 0);
        $size  = (int) ($fileStat['size'] ?? 0);

        if (self::statMetaEnabled()) {
            $metaPath = self::statMetaPath($real);
            if ($metaPath !== null) {
                $meta = self::readStatMeta($metaPath);
                if ($meta !== null
                    && (int) ($meta['mtime'] ?? -1) === $mtime
                    && (int) ($meta['size'] ?? -1) === $size
                    && isset($meta['h'])
                    && is_string($meta['h'])
                    && $meta['h'] !== ''
                ) {
                    $hit = self::tryLoadAstCache($real, $meta['h']);
                    if ($hit !== null) {
                        return $hit;
                    }
                }
            }
        }

        $code = @file_get_contents($real);
        if ($code === false) {
            return null;
        }

        $hash = hash('sha256', $code);
        $fromAstDisk = self::tryLoadAstCache($real, $hash);
        if ($fromAstDisk !== null) {
            self::persistStatMeta($real, $mtime, $size, $hash);

            return $fromAstDisk;
        }

        $stmts = self::parseCode($code);
        if ($stmts === null) {
            return null;
        }

        self::persistAstCache($real, $hash, $stmts);
        self::persistStatMeta($real, $mtime, $size, $hash);

        return $stmts;
    }

    /**
     * @return list<Node>|null
     */
    private static function tryLoadAstCache(string $realPath, string $contentHash): ?array
    {
        $cacheFp = self::cacheFilePath($realPath, $contentHash);
        if ($cacheFp === null || ! is_file($cacheFp)) {
            return null;
        }

        $cached = @file_get_contents($cacheFp);
        if ($cached === false) {
            return null;
        }

        $payload = @unserialize($cached, ['allowed_classes' => true]);
        if (! is_array($payload)
            || ($payload['v'] ?? null) !== self::CACHE_VERSION
            || ($payload['h'] ?? null) !== $contentHash
            || ! isset($payload['s'])
            || ! is_array($payload['s'])
        ) {
            return null;
        }

        /** @var list<Node> $stmts */
        $stmts = $payload['s'];

        return $stmts;
    }

    /**
     * @param list<Node> $stmts
     */
    private static function persistAstCache(string $realPath, string $contentHash, array $stmts): void
    {
        $cacheFp = self::cacheFilePath($realPath, $contentHash);
        if ($cacheFp === null) {
            return;
        }

        $dir = dirname($cacheFp);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $bytes = serialize([
            'v' => self::CACHE_VERSION,
            'h' => $contentHash,
            's' => $stmts,
        ]);
        @file_put_contents($cacheFp, $bytes, LOCK_EX);
    }

    /**
     * @return array{mtime: int, size: int, h: string}|null
     */
    private static function readStatMeta(string $absoluteMetaPath): ?array
    {
        $raw = @file_get_contents($absoluteMetaPath);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return null;
        }

        if (($data['sv'] ?? null) !== self::STAT_META_VERSION) {
            return null;
        }

        if (! isset($data['mtime'], $data['size'], $data['h'])) {
            return null;
        }

        return [
            'mtime' => (int) $data['mtime'],
            'size'  => (int) $data['size'],
            'h'     => (string) $data['h'],
        ];
    }

    private static function persistStatMeta(string $realPath, int $mtime, int $size, string $contentHash): void
    {
        $metaPath = self::statMetaPath($realPath);
        if ($metaPath === null) {
            return;
        }

        $dir = dirname($metaPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        try {
            $json = json_encode([
                'sv'    => self::STAT_META_VERSION,
                'mtime' => $mtime,
                'size'  => $size,
                'h'     => $contentHash,
            ], JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return;
        }

        @file_put_contents($metaPath, $json, LOCK_EX);
    }

    private static function statMetaPath(string $realPath): ?string
    {
        $root = self::cacheRootDirectory();
        if ($root === null) {
            return null;
        }

        $key = hash('sha256', $realPath . "\0stat-meta\0" . self::CACHE_VERSION . "\0" . self::STAT_META_VERSION);
        $sub = substr($key, 0, 2);

        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $sub . DIRECTORY_SEPARATOR . $key . '.stat-meta';
    }

    private static function statMetaEnabled(): bool
    {
        try {
            if (! function_exists('app') || ! function_exists('config') || ! app()->bound('config')) {
                return true;
            }

            $cfg = config('deadcode.cache', []);

            return ($cfg['stat_cache'] ?? true) !== false;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @return list<Node>|null
     */
    private static function parseCode(string $code): ?array
    {
        try {
            $stmts = AstParserFactory::createParser()->parse($code);
        } catch (Error $e) {
            return null;
        }

        return $stmts;
    }

    private static function cacheEnabled(): bool
    {
        try {
            if (! function_exists('app') || ! function_exists('config')) {
                return false;
            }
            if (! app()->bound('config')) {
                return false;
            }

            $cfg = config('deadcode.cache', []);

            return ($cfg['enabled'] ?? true) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function cacheFilePath(string $realPath, string $contentHash): ?string
    {
        $root = self::cacheRootDirectory();
        if ($root === null) {
            return null;
        }

        $key = hash('sha256', $realPath . "\0" . $contentHash . "\0" . self::CACHE_VERSION);
        $sub = substr($key, 0, 2);

        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $sub . DIRECTORY_SEPARATOR . $key . '.ast.cache';
    }

    private static function cacheRootDirectory(): ?string
    {
        $root = null;
        try {
            if (function_exists('app') && function_exists('config') && app()->bound('config')) {
                $p = config('deadcode.cache.path');
                if (is_string($p) && $p !== '') {
                    $root = $p;
                }
            }
        } catch (\Throwable) {
            $root = null;
        }

        if ($root === null && function_exists('storage_path')) {
            try {
                $root = storage_path('framework/deadcode');
            } catch (\Throwable) {
                $root = null;
            }
        }

        return $root;
    }
}
