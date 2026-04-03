<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

/**
 * Low-level discovery of filesystem roots used by {@see ScanPathResolver}.
 *
 * Includes when directories exist:
 * - app (plus first-level subdirs of app/, and known app/* segments such as Http/, Models/, …)
 * - routes, bootstrap, optional base_path('src')
 * - resources and resources/views
 * - config_path()
 * - database_path() and database/{migrations,seeders,factories}
 * - composer.json autoload & autoload-dev psr-4 directories
 *
 * Final merge order and config flags live in {@see ScanPathResolver}.
 */
final class ProjectStructureScanner
{
    /**
     * Extra app/* path segments relative to app_path() that are merged into global scans
     * when those directories exist (Livewire, Filament, etc.).
     *
     * @var list<string>
     */
    private const EXTRA_APP_SUBDIRS = [
        'Http/Controllers',
        'Http/Middleware',
        'Http/Requests',
        'Http/Resources',
        'Console/Commands',
        'Console/Scheduling',
        'Models',
        'Policies',
        'Providers',
        'Jobs',
        'Events',
        'Listeners',
        'Observers',
        'Notifications',
        'Mail',
        'Rules',
        'Enums',
        'Actions',
        'Services',
        'Repositories',
        'Livewire',
        'Filament',
        'GraphQL',
        'Exports',
        'Imports',
        'Contracts',
        'ValueObjects',
        'Transformers',
    ];

    /**
     * Maps deadcode analyzer config keys → app/* segments (only existing dirs are returned).
     *
     * @var array<string, list<string>>
     */
    private const ANALYZER_TO_APP_SEGMENTS = [
        'controllers'        => ['Http/Controllers'],
        'middlewares'        => ['Http/Middleware'],
        'requests'           => ['Http/Requests'],
        'resources'          => ['Http/Resources', 'Transformers'],
        'models'             => ['Models'],
        'policies'           => ['Policies'],
        'service_bindings'   => ['Providers'],
        'commands'           => ['Console/Commands', 'Console/Scheduling'],
        'notifications'      => ['Notifications'],
        'mailables'          => ['Mail'],
        'rules'              => ['Rules'],
        'enums'              => ['Enums'],
        'actions'            => ['Actions'],
        'services'           => ['Services', 'Repositories'],
        'jobs'               => ['Jobs'],
        'events'             => ['Events'],
        'listeners'          => ['Listeners'],
        'observers'          => ['Observers'],
    ];

    /**
     * Roots used for "whole codebase" style scans (merged into each analyzer's scan paths).
     *
     * @return list<string>
     */
    public static function discoverGlobalRoots(): array
    {
        /** @var array<string, true> */
        $seen = [];
        $add = static function (string $path) use (&$seen): void {
            if ($path === '' || ! is_dir($path)) {
                return;
            }
            $r = realpath($path);
            if ($r === false) {
                return;
            }
            $seen[strtolower($r)] = true;
        };

        if (function_exists('app_path')) {
            $add(app_path());
            foreach (self::EXTRA_APP_SUBDIRS as $rel) {
                $add(app_path($rel));
            }

            $appRoot = app_path();
            if (is_dir($appRoot)) {
                foreach (new \DirectoryIterator($appRoot) as $item) {
                    if ($item->isDot() || ! $item->isDir()) {
                        continue;
                    }
                    $add($item->getPathname());
                }
            }
        }

        if (function_exists('resource_path')) {
            $add(resource_path('views'));
            $add(resource_path());
        }

        if (function_exists('base_path')) {
            $add(base_path('routes'));
            $add(base_path('bootstrap'));
            if (is_dir(base_path('src'))) {
                $add(base_path('src'));
            }
        }

        if (function_exists('database_path')) {
            $add(database_path());
            $add(database_path('migrations'));
            $add(database_path('seeders'));
            $add(database_path('factories'));
        }

        if (function_exists('config_path')) {
            $add(config_path());
        }

        self::mergeComposerPsr4Directories($add);

        return array_keys($seen);
    }

    /**
     * Convention paths for one built-in analyzer (existing directories only).
     *
     * @return list<string>
     */
    public static function pathsForAnalyzer(string $analyzerKey): array
    {
        if ($analyzerKey === 'migrations' && function_exists('database_path')) {
            $p = database_path('migrations');

            return is_dir($p) && ($r = realpath($p)) !== false ? [$r] : [];
        }

        if ($analyzerKey === 'routes' && function_exists('base_path')) {
            $p = base_path('routes');

            return is_dir($p) && ($r = realpath($p)) !== false ? [$r] : [];
        }

        if ($analyzerKey === 'views' && function_exists('resource_path')) {
            $p = resource_path('views');

            return is_dir($p) && ($r = realpath($p)) !== false ? [$r] : [];
        }

        $rels = self::ANALYZER_TO_APP_SEGMENTS[$analyzerKey] ?? [];
        $out  = [];
        foreach ($rels as $rel) {
            if ($rel === '' || ! function_exists('app_path')) {
                continue;
            }
            $path = app_path($rel);
            if (is_dir($path) && ($r = realpath($path)) !== false) {
                $out[] = $r;
            }
        }

        return self::dedupePaths($out);
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    public static function dedupePaths(array $paths): array
    {
        $seen = [];
        $out  = [];
        foreach ($paths as $p) {
            if (! is_string($p) || $p === '') {
                continue;
            }
            $r = realpath($p) ?: $p;
            $k = strtolower(str_replace('\\', '/', $r));
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[]    = $r;
        }

        return $out;
    }

    /**
     * @param callable(string): void $add
     */
    private static function mergeComposerPsr4Directories(callable $add): void
    {
        if (! function_exists('base_path')) {
            return;
        }

        $composer = base_path('composer.json');
        if (! is_file($composer)) {
            return;
        }

        $json = json_decode((string) file_get_contents($composer), true);
        if (! is_array($json)) {
            return;
        }

        foreach (['autoload', 'autoload-dev'] as $section) {
            $psr4 = $json[$section]['psr-4'] ?? [];
            if (! is_array($psr4)) {
                continue;
            }
            foreach ($psr4 as $prefix => $dir) {
                $dirs = is_array($dir) ? $dir : [$dir];
                foreach ($dirs as $d) {
                    if (! is_string($d) || $d === '') {
                        continue;
                    }
                    $path = self::normalizeComposerPath($d);
                    $add($path);
                }
            }
        }
    }

    private static function normalizeComposerPath(string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        $relative = rtrim($relative, '/');

        return base_path($relative);
    }
}
