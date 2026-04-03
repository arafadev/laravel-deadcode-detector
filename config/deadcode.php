<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Auto-discover project layout (path discovery layer)
    |--------------------------------------------------------------------------
    | When true, global scan roots come from ProjectStructureScanner: app (and every
    | direct child directory under app/), routes, bootstrap, resources, config,
    | database (whole tree + migrations/seeders/factories), optional src/, and
    | PSR-4 paths from composer.json (autoload + autoload-dev).
    |
    | Global path merge order (see ScanPathResolver::globalScanPaths):
    |   1) auto_discover roots  2) scan_paths  3) paths.extra  4) fallback app_path()
    |
    | Per analyzer: non-empty analyzer_paths.{key} overrides convention + defaultScanPaths
    | for that analyzer’s primary roots; global roots above are always merged afterward.
    |
    | Set auto_discover false only if you want full control via scan_paths / paths.extra.
    */
    'auto_discover' => env('DEADCODE_AUTO_DISCOVER', true),

    'analyzers' => [
        'controllers'       => true,
        'models'            => true,
        'views'             => true,
        'routes'            => true,
        'middlewares'       => true,
        'migrations'        => true,
        'helpers'           => true,
        'requests'          => true,
        'resources'         => true,
        'policies'          => true,
        'actions'           => true,
        'services'          => true,
        'commands'          => true,
        'notifications'     => true,
        'mailables'         => true,
        'rules'             => true,
        'enums'             => true,
        'jobs'              => true,
        'events'            => true,
        'listeners'         => true,
        'observers'         => true,
        'service_bindings'  => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Scan Paths Per Analyzer
    |--------------------------------------------------------------------------
    | By default each analyzer knows its own folder (e.g. app/Jobs for jobs).
    | You can OVERRIDE or EXTEND the paths per analyzer key here.
    |
    | Example:
    |   'jobs' => [base_path('app/BackgroundTasks'), app_path('Jobs')],
    |   'services' => [app_path('Services'), app_path('Domain/Services')],
    |
    | When you set paths here, they replace auto-discovered + default paths for
    | that analyzer only (global auto-discovered roots are still merged in).
    */
    'analyzer_paths' => [
        // 'jobs'        => [app_path('Jobs')],
        // 'services'    => [app_path('Services')],
        // 'actions'     => [app_path('Actions')],
        // 'enums'       => [app_path('Enums')],
        // 'rules'       => [app_path('Rules')],
        // 'mailables'   => [app_path('Mail')],
        // 'commands'    => [app_path('Console/Commands')],
        // 'requests'    => [app_path('Http/Requests')],
        // 'resources'   => [app_path('Http/Resources')],
        // 'policies'    => [app_path('Policies')],
        // 'observers'   => [app_path('Observers')],
        // 'listeners'   => [app_path('Listeners')],
        // 'events'      => [app_path('Events')],
        // 'notifications' => [app_path('Notifications')],
    ],

    'helper_paths' => [],

    'custom_analyzers' => [],

    /*
    |--------------------------------------------------------------------------
    | Extra global roots (optional)
    |--------------------------------------------------------------------------
    | Appended to every analyzer’s scan list after auto_discover and scan_paths.
    */
    'paths' => [
        'extra' => [
            // app_path('Modules/MyModule'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Additional global scan roots
    |--------------------------------------------------------------------------
    | Use when auto_discover is off, or to add roots on top of discovery.
    */
    'scan_paths' => [
        // e.g. base_path('src')
    ],

    /*
    |--------------------------------------------------------------------------
    | Smart exclusions
    |--------------------------------------------------------------------------
    | exclude_builtin: vendor, node_modules, storage, bootstrap/cache, .git (prefix match).
    | exclude_paths: extra fragments, absolute paths, or fnmatch patterns (e.g. app/Legacy/*).
    */
    'exclude_builtin' => env('DEADCODE_EXCLUDE_BUILTIN', true),

    'exclude_paths' => [],

    /*
    |--------------------------------------------------------------------------
    | User ignore (findings only)
    |--------------------------------------------------------------------------
    | Suppress specific dead-code findings after analysis. The graph still sees these
    | files, so other items are not misclassified when you ignore a false positive.
    |
    | - classes:      FQCN list (e.g. App\Http\Controllers\Admin\LegacyController)
    | - folders:      Relative to base_path() or absolute; matches any finding whose
    |                 file path is inside the directory (or equals a single file path)
    | - patterns:     fnmatch() globs relative to base_path() or absolute (* and ?)
    |
    | Inline (file-wide): add a line or block comment with @deadcode-ignore anywhere
    | in the first part of the file, e.g. // @deadcode-ignore
    | Blade: {{-- @deadcode-ignore --}}
    */
    'ignore' => [
        'classes'  => [
            // 'App\\Http\\Controllers\\DeprecatedController',
        ],
        'folders'  => [
            // 'app/Legacy',
        ],
        'patterns' => [
            // 'app/**/*Draft*.php',
        ],
    ],

];
