<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector;

use Illuminate\Support\ServiceProvider;
use Arafa\DeadcodeDetector\Analyzers\ActionsAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\CommandsAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\ControllersAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\DebugStatementsAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\EnumsAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\EventsAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\HelpersAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\JobsAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\ListenersAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\MailablesAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\MigrationsAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\MiddlewaresAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\ModelsAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\NotificationsAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\ObserversAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\PoliciesAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\RequestsAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\ResourcesAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\RoutesAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\RulesAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\ServiceBindingsAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\ServicesAnalyzer;
use Arafa\DeadcodeDetector\Analyzers\ViewsAnalyzer;
use Arafa\DeadcodeDetector\Commands\DeadScanCommand;
use Arafa\DeadcodeDetector\Support\DeadcodeResultIgnoreFilter;
use Arafa\DeadcodeDetector\Support\PathExcludeMatcher;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;
use Arafa\DeadcodeDetector\Support\ScanPathResolver;

class DeadCodeServiceProvider extends ServiceProvider
{
    private const BUILTIN_ANALYZERS = [
        'controllers'       => ControllersAnalyzer::class,
        'models'            => ModelsAnalyzer::class,
        'views'             => ViewsAnalyzer::class,
        'routes'            => RoutesAnalyzer::class,
        'middlewares'       => MiddlewaresAnalyzer::class,
        'migrations'        => MigrationsAnalyzer::class,
        'helpers'           => HelpersAnalyzer::class,
        'requests'          => RequestsAnalyzer::class,
        'resources'         => ResourcesAnalyzer::class,
        'policies'          => PoliciesAnalyzer::class,
        'actions'           => ActionsAnalyzer::class,
        'services'          => ServicesAnalyzer::class,
        'commands'          => CommandsAnalyzer::class,
        'notifications'     => NotificationsAnalyzer::class,
        'mailables'         => MailablesAnalyzer::class,
        'rules'             => RulesAnalyzer::class,
        'enums'             => EnumsAnalyzer::class,
        'jobs'              => JobsAnalyzer::class,
        'events'            => EventsAnalyzer::class,
        'listeners'         => ListenersAnalyzer::class,
        'observers'         => ObserversAnalyzer::class,
        'service_bindings'  => ServiceBindingsAnalyzer::class,
        'debug_statements'  => DebugStatementsAnalyzer::class,
    ];

    public array $commands = [DeadScanCommand::class];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/deadcode.php', 'deadcode');

        $this->app->singleton(PathExcludeMatcher::class, function ($app) {
            return PathExcludeMatcher::fromApplication($app);
        });

        $this->app->singleton(DeadcodeResultIgnoreFilter::class, function ($app) {
            return DeadcodeResultIgnoreFilter::fromApplication($app);
        });

        $this->app->singleton(PhpFileScanner::class, function ($app) {
            return new PhpFileScanner(null, $app->make(PathExcludeMatcher::class));
        });

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
        $deadcode            = $this->app['config']->get('deadcode', []);
        if (! is_array($deadcode)) {
            $deadcode = [];
        }

        $globalScanPaths = ScanPathResolver::globalScanPaths($deadcode);
        $pathExclude     = $this->app->make(PathExcludeMatcher::class);

        foreach (self::BUILTIN_ANALYZERS as $key => $concreteClass) {
            $value = $configuredAnalyzers[$key] ?? false;
            if ($value === false) {
                continue;
            }

            $resolvedClass = (is_string($value) && class_exists($value)) ? $value : $concreteClass;
            $scanPaths     = ScanPathResolver::analyzerScanPaths($key, $resolvedClass, $globalScanPaths, $deadcode);

            $this->app->bind($resolvedClass, function () use ($resolvedClass, $scanPaths, $pathExclude) {
                if ($resolvedClass === HelpersAnalyzer::class) {
                    return new HelpersAnalyzer(
                        $this->app->make(PhpFileScanner::class),
                        $scanPaths,
                        $pathExclude,
                        $this->app['config']->get('deadcode.helper_paths', []),
                    );
                }

                return new $resolvedClass(
                    $this->app->make(PhpFileScanner::class),
                    $scanPaths,
                    $pathExclude,
                );
            });
        }
    }

    private function bindCustomAnalyzers(): void
    {
        $customAnalyzers = $this->app['config']->get('deadcode.custom_analyzers', []);
        $deadcode        = $this->app['config']->get('deadcode', []);
        if (! is_array($deadcode)) {
            $deadcode = [];
        }
        $globalScanPaths = ScanPathResolver::globalScanPaths($deadcode);
        $pathExclude     = $this->app->make(PathExcludeMatcher::class);

        foreach ($customAnalyzers as $analyzerClass) {
            if (! class_exists($analyzerClass) || $this->app->bound($analyzerClass)) {
                continue;
            }

            $this->app->bind($analyzerClass, function () use ($analyzerClass, $globalScanPaths, $pathExclude) {
                if ($analyzerClass === HelpersAnalyzer::class) {
                    return new HelpersAnalyzer(
                        $this->app->make(PhpFileScanner::class),
                        $globalScanPaths,
                        $pathExclude,
                        $this->app['config']->get('deadcode.helper_paths', []),
                    );
                }

                return new $analyzerClass(
                    $this->app->make(PhpFileScanner::class),
                    $globalScanPaths,
                    $pathExclude,
                );
            });
        }
    }
}
