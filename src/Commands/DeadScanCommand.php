<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Throwable;
use Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface;
use Arafa\DeadcodeDetector\Reporters\ConsoleReporter;
use Arafa\DeadcodeDetector\Reporters\JsonReporter;
use Arafa\DeadcodeDetector\Support\PlainTextReportWriter;

class DeadScanCommand extends Command
{
    protected $signature = 'dead:scan
                            {--details        : Show detailed table output (default behaviour)}
                            {--format=console : Terminal output: console (human tables). Use --format=json only when redirecting JSON to a file (JSON is never mixed into the normal console report).}
                            {--interactive    : Prompt before each potential deletion}
                            {--output=        : Save the complete report to this file (UTF-8). Use when there are many findings — terminal scrollback is limited.}
                            {--compact        : One line per finding in the terminal (less scrolling)}
                            {--only-summary   : With --output, skip tables in the terminal (only counts + file path)}';

    protected $description = 'Scan your Laravel application for dead / unused code.';

    /** Map config key → analyzer FQCN (mirrors ServiceProvider::BUILTIN_ANALYZERS) */
    private const BUILTIN_MAP = [
        'controllers' => \Arafa\DeadcodeDetector\Analyzers\ControllersAnalyzer::class,
        'models'      => \Arafa\DeadcodeDetector\Analyzers\ModelsAnalyzer::class,
        'views'       => \Arafa\DeadcodeDetector\Analyzers\ViewsAnalyzer::class,
        'routes'      => \Arafa\DeadcodeDetector\Analyzers\RoutesAnalyzer::class,
        'middlewares' => \Arafa\DeadcodeDetector\Analyzers\MiddlewaresAnalyzer::class,
        'migrations'  => \Arafa\DeadcodeDetector\Analyzers\MigrationsAnalyzer::class,
        'helpers'     => \Arafa\DeadcodeDetector\Analyzers\HelpersAnalyzer::class,
    ];

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $format = (string) $this->option('format');
        if ($format === 'both') {
            $format = 'console';
        }
        $interactive  = (bool) $this->option('interactive');
        $outputRaw   = $this->option('output');
        $outputPath  = is_string($outputRaw) ? trim($outputRaw) : '';
        $compact     = (bool) $this->option('compact');
        $onlySummary = (bool) $this->option('only-summary');

        $this->info('');
        $this->info('  🔍 <options=bold>Laravel Dead Code Detector</>');
        $this->info('  ─────────────────────────────────────────');

        $analyzerClasses = $this->resolveEnabledAnalyzers();

        if (empty($analyzerClasses)) {
            $this->warn('  No analyzers are enabled. Check your config/deadcode.php file.');
            return self::SUCCESS;
        }

        $this->info(sprintf('  Running <fg=cyan>%d</> analyzer(s)...', count($analyzerClasses)));
        $this->info('');

        $allResults = [];
        $errors     = [];

        foreach ($analyzerClasses as $key => $analyzerClass) {
            try {
                /** @var AnalyzerInterface $analyzer */
                $analyzer = $this->container->make($analyzerClass);

                $this->line("  ↳ <comment>{$analyzer->getName()}</comment>: {$analyzer->getDescription()}");

                $results    = $analyzer->analyze();
                $allResults = array_merge($allResults, $results);

                $count = count($results);
                $label = $count === 0
                    ? '<fg=green>✓ Clean</>'
                    : "<fg=red>✗ {$count} found</>";

                $this->line("     {$label}");

            } catch (Throwable $e) {
                $errors[$key] = $e->getMessage();
                $this->warn("  ✗ Analyzer [{$key}] failed: {$e->getMessage()}");
            }
        }

        $this->info('');
        $this->info('  ─────────────────────────────────────────');

        if ($outputPath !== '') {
            try {
                PlainTextReportWriter::write($outputPath, $allResults);
                $this->info(sprintf(
                    '  <fg=green>✓</> Full report written (<fg=cyan>%d</> item(s)): <fg=white>%s</>',
                    count($allResults),
                    $outputPath
                ));
                $this->info('  <fg=gray>(Open this file in your editor — the terminal cannot show unlimited lines.)</>');
                $this->info('');
            } catch (Throwable $e) {
                $this->error('  Failed to write --output file: ' . $e->getMessage());
            }
        }

        // ── Reporters ─────────────────────────────────────────────────────────
        $skipConsoleDetail = $onlySummary && $outputPath !== '';

        if ($format === 'console' && ! $skipConsoleDetail) {
            $reporter = new ConsoleReporter($this->output, $compact);
            $reporter->report($allResults);
        }

        if ($skipConsoleDetail && $allResults !== []) {
            $this->line(sprintf(
                '  <fg=yellow>Summary only:</> <fg=red;options=bold>%d</> NOT USED item(s) (see <fg=white>%s</>)',
                count($allResults),
                $outputPath
            ));
            $this->info('');
        }

        if ($format === 'json') {
            $reporter = new JsonReporter($this->output);
            $reporter->report($allResults);
        }

        // ── Interactive mode ──────────────────────────────────────────────────
        if ($interactive && ! empty($allResults)) {
            $this->info('  <options=bold>Interactive Mode</> — review each item:');
            foreach ($allResults as $result) {
                $answer = $this->confirm(
                    "  Mark [{$result->filePath}] for deletion?",
                    false
                );
                if ($answer) {
                    $this->line("  <fg=yellow>→ Marked:</> {$result->filePath}");
                }
            }
        }

        // ── Errors summary ────────────────────────────────────────────────────
        if (! empty($errors)) {
            $this->warn('');
            $this->warn('  Analyzers that encountered errors:');
            foreach ($errors as $key => $message) {
                $this->line("  • [{$key}] {$message}");
            }
        }

        return self::SUCCESS;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resolveEnabledAnalyzers(): array
    {
        $configAnalyzers = config('deadcode.analyzers', []);
        $customAnalyzers = config('deadcode.custom_analyzers', []);
        $enabled         = [];

        // Built-ins
        foreach (self::BUILTIN_MAP as $key => $defaultClass) {
            $value = $configAnalyzers[$key] ?? false;

            if ($value === false) continue;

            $resolvedClass = (is_string($value) && class_exists($value))
                ? $value
                : $defaultClass;

            $enabled[$key] = $resolvedClass;
        }

        // Custom analyzers
        foreach ($customAnalyzers as $class) {
            if (class_exists($class)) {
                $enabled[$class] = $class;
            }
        }

        return $enabled;
    }
}
