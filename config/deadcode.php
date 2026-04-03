<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Built-in Analyzers
    |--------------------------------------------------------------------------
    | true  = enabled (uses built-in class)
    | false = disabled
    | FQCN  = use your own custom implementation instead
    */
    'analyzers' => [
        'controllers' => true,
        'models'      => true,
        'views'       => true,
        'routes'      => true,
        'middlewares' => true,
        'migrations'  => true,
        'helpers'     => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Helper files (optional)
    |--------------------------------------------------------------------------
    | Extra PHP files that define global/namespaced functions (in addition to
    | composer.json "autoload.files" and common defaults like app/helpers.php).
    |
    | Example:
    |   base_path('app/Support/custom_helpers.php'),
    */
    'helper_paths' => [
        // base_path('app/Helpers/helper.php'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Analyzers
    |--------------------------------------------------------------------------
    | Add your own analyzer classes here. Each must implement AnalyzerInterface.
    |
    | Example:
    |   \App\DeadCode\UnusedJobsAnalyzer::class,
    */
    'custom_analyzers' => [
        // \App\DeadCode\UnusedJobsAnalyzer::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Paths to Scan
    |--------------------------------------------------------------------------
    */
    'scan_paths' => [
        app_path(),
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Paths
    |--------------------------------------------------------------------------
    | Any path containing one of these strings will be skipped.
    */
    'exclude_paths' => [
        // 'tests',
        // 'vendor',
    ],

];
