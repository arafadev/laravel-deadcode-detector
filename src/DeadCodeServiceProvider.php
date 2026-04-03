<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector;

use Illuminate\Support\ServiceProvider;
use Arafa\DeadcodeDetector\Analyzers\ControllersAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\HelpersAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\MigrationsAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\MiddlewaresAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\ModelsAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\RoutesAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\ViewsAnalyzer;
use Arafa\DeadcodeDetector\Commands\DeadScanCommand;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;

class DeadCodeServiceProvider extends ServiceProvider
{
    private const BUILTIN_ANALYZERS = [
        'controllers' => ControllersAnalyzer::class,
        'models'      => ModelsAnalyzer::class,
        'views'       => ViewsAnalyzer::class,
        'routes'      => RoutesAnalyzer::class,
        'middlewares' => MiddlewaresAnalyzer::class,
        'migrations'  => MigrationsAnalyzer::class,
        'helpers'     => HelpersAnalyzer::class,
    ];

    public array $commands = [DeadScanCommand::class];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/deadcode.php', 'deadcode');
        $this->app->singleton(PhpFileScanner::class);
        $this->bindBuiltinAnalyzers();
        $this->bindCustomAnalyzers();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/deadcode.php' => config_path('deadcode.php'),
            ], 'deadcode-config');

            $this->commands($this->commands);
        }
    }

    private function bindBuiltinAnalyzers(): void
    {
        $configuredAnalyzers = $this->app['config']->get('deadcode.analyzers', []);
        $scanPaths           = $this->app['config']->get('deadcode.scan_paths', [app_path()]);
        $excludePaths        = $this->app['config']->get('deadcode.exclude_paths', []);

        foreach (self::BUILTIN_ANALYZERS as $key => $concreteClass) {
            $value = $configuredAnalyzers[$key] ?? false;
            if ($value === false) continue;

            $resolvedClass = (is_string($value) && class_exists($value)) ? $value : $concreteClass;

            $this->app->bind($resolvedClass, function () use ($resolvedClass, $scanPaths, $excludePaths) {
                if ($resolvedClass === HelpersAnalyzer::class) {
                    return new HelpersAnalyzer(
                        $this->app->make(PhpFileScanner::class),
                        $scanPaths,
                        $excludePaths,
                        $this->app['config']->get('deadcode.helper_paths', []),
                    );
                }

                return new $resolvedClass(
                    $this->app->make(PhpFileScanner::class),
                    $scanPaths,
                    $excludePaths,
                );
            });
        }
    }

    private function bindCustomAnalyzers(): void
    {
        $customAnalyzers = $this->app['config']->get('deadcode.custom_analyzers', []);
        $scanPaths       = $this->app['config']->get('deadcode.scan_paths', [app_path()]);
        $excludePaths    = $this->app['config']->get('deadcode.exclude_paths', []);

        foreach ($customAnalyzers as $analyzerClass) {
            if (!class_exists($analyzerClass) || $this->app->bound($analyzerClass)) continue;

            $this->app->bind($analyzerClass, function () use ($analyzerClass, $scanPaths, $excludePaths) {
                return new $analyzerClass(
                    $this->app->make(PhpFileScanner::class),
                    $scanPaths,
                    $excludePaths,
                );
            });
        }
    }
}
