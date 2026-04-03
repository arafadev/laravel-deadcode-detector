<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface;
use Arafa\DeadcodeDetector\Reporters\ConsoleReporter;
use Arafa\DeadcodeDetector\Reporters\JsonReporter;
use Arafa\DeadcodeDetector\Support\CliScanPreflight;
use Arafa\DeadcodeDetector\Support\DeadcodeResultIgnoreFilter;
use Arafa\DeadcodeDetector\Support\InteractiveDeadcodeWorkflow;
use Arafa\DeadcodeDetector\Support\DetectionConfidence;
use Arafa\DeadcodeDetector\Support\PathExcludeMatcher;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;
use Arafa\DeadcodeDetector\Support\PlainTextReportWriter;
use Arafa\DeadcodeDetector\Support\ReportPayloadBuilder;

class DeadScanCommand extends Command
{
    protected $signature = 'dead:scan
                            {--details        : Show detailed table output (default behaviour)}
                            {--format=console : Terminal output: console (human tables). Use --format=json only when redirecting JSON to a file (JSON is never mixed into the normal console report).}
                            {--interactive    : Step through each finding: Delete (confirmed), Ignore (inline @deadcode-ignore), or Skip — console only}
                            {--output=        : Save report to a file. Use .json for structured JSON (same schema as --format=json). Any other extension saves a plain-text report (UTF-8).}
                            {--compact        : One line per finding in the terminal (less scrolling)}
                            {--only-summary   : With --output, skip tables in the terminal (only counts + file path)}';

    protected $description = 'Scan your Laravel application for dead / unused code. Each finding includes contextual hints and safe next steps (review / delete only after confirmation / config exclude). Use --interactive with console format to delete files (double-confirmed), add // @deadcode-ignore, or skip each item. Suppress false positives via config/deadcode.php → ignore or inline markers (see config). Use -v for per-analyzer detail; use -q for minimal output.';

    /** Map config key → analyzer FQCN (mirrors ServiceProvider::BUILTIN_ANALYZERS) */
    private const BUILTIN_MAP = [
        'controllers'       => \Arafa\DeadcodeDetector\Analyzers\ControllersAnalyzer::class,
        'models'            => \Arafa\DeadcodeDetector\Analyzers\ModelsAnalyzer::class,
        'views'             => \Arafa\DeadcodeDetector\Analyzers\ViewsAnalyzer::class,
        'routes'            => \Arafa\DeadcodeDetector\Analyzers\RoutesAnalyzer::class,
        'middlewares'       => \Arafa\DeadcodeDetector\Analyzers\MiddlewaresAnalyzer::class,
        'migrations'        => \Arafa\DeadcodeDetector\Analyzers\MigrationsAnalyzer::class,
        'helpers'           => \Arafa\DeadcodeDetector\Analyzers\HelpersAnalyzer::class,
        'requests'          => \Arafa\DeadcodeDetector\Analyzers\RequestsAnalyzer::class,
        'resources'         => \Arafa\DeadcodeDetector\Analyzers\ResourcesAnalyzer::class,
        'policies'          => \Arafa\DeadcodeDetector\Analyzers\PoliciesAnalyzer::class,
        'actions'           => \Arafa\DeadcodeDetector\Analyzers\ActionsAnalyzer::class,
        'services'          => \Arafa\DeadcodeDetector\Analyzers\ServicesAnalyzer::class,
        'commands'          => \Arafa\DeadcodeDetector\Analyzers\CommandsAnalyzer::class,
        'notifications'     => \Arafa\DeadcodeDetector\Analyzers\NotificationsAnalyzer::class,
        'mailables'         => \Arafa\DeadcodeDetector\Analyzers\MailablesAnalyzer::class,
        'rules'             => \Arafa\DeadcodeDetector\Analyzers\RulesAnalyzer::class,
        'enums'             => \Arafa\DeadcodeDetector\Analyzers\EnumsAnalyzer::class,
        'jobs'              => \Arafa\DeadcodeDetector\Analyzers\JobsAnalyzer::class,
        'events'            => \Arafa\DeadcodeDetector\Analyzers\EventsAnalyzer::class,
        'listeners'         => \Arafa\DeadcodeDetector\Analyzers\ListenersAnalyzer::class,
        'observers'         => \Arafa\DeadcodeDetector\Analyzers\ObserversAnalyzer::class,
        'service_bindings'  => \Arafa\DeadcodeDetector\Analyzers\ServiceBindingsAnalyzer::class,
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
        $outputRaw    = $this->option('output');
        $outputPath   = is_string($outputRaw) ? trim($outputRaw) : '';
        $compact      = (bool) $this->option('compact');
        $onlySummary  = (bool) $this->option('only-summary');

        if (! $this->output->isQuiet()) {
            $this->renderHeader();
        }

        $analyzerClasses = $this->resolveEnabledAnalyzers();

        if ($analyzerClasses === []) {
            if (! $this->output->isQuiet()) {
                $this->warn('  <fg=yellow>No analyzers are enabled.</> Check <fg=white>config/deadcode.php</>.');
            }

            return self::SUCCESS;
        }

        $scanner = $this->container->make(PhpFileScanner::class);
        $exclude = $this->container->make(PathExcludeMatcher::class);

        $phpFilesInScope = CliScanPreflight::countUniquePhpFilesInMergedScope($scanner, $exclude, $analyzerClasses);

        $allResults = [];
        $errors     = [];

        $useProgressBar = $format === 'console'
            && ! $this->output->isQuiet()
            && $this->output->getVerbosity() === OutputInterface::VERBOSITY_NORMAL;

        $bar = null;
        if ($useProgressBar) {
            $bar = $this->output->createProgressBar(count($analyzerClasses));
            $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%  %message%');
            $bar->setMessage('starting…');
            $bar->start();
        } else {
            if (! $this->output->isQuiet()) {
                $this->line(sprintf(
                    '  <fg=gray>Running</> <fg=cyan>%d</> <fg=gray>analyzer(s)…</>',
                    count($analyzerClasses)
                ));
                $this->line('');
            }
        }

        foreach ($analyzerClasses as $key => $analyzerClass) {
            try {
                /** @var AnalyzerInterface $analyzer */
                $analyzer = $this->container->make($analyzerClass);

                if ($bar !== null) {
                    $bar->setMessage($analyzer->getName());
                } elseif (! $this->output->isQuiet()) {
                    $this->line(sprintf('  <fg=gray>▸</> <fg=cyan>%s</>', $analyzer->getName()));
                    if ($this->output->isVerbose()) {
                        $this->line(sprintf('    <fg=gray>%s</>', $analyzer->getDescription()));
                    }
                }

                $results    = $analyzer->analyze();
                $allResults = array_merge($allResults, $results);

                if ($bar === null && ! $this->output->isQuiet()) {
                    $count = count($results);
                    $label = $count === 0
                        ? '<fg=green>✓ clean</>'
                        : "<fg=red>✗ {$count} finding(s)</>";
                    $this->line(sprintf('     %s', $label));
                }

                $bar?->advance();
            } catch (Throwable $e) {
                $errors[$key] = $e->getMessage();
                $bar?->advance();
                if (! $this->output->isQuiet()) {
                    $this->newLine();
                    $this->warn("  <fg=yellow>Analyzer failed</> [<fg=white>{$key}</>]: {$e->getMessage()}");
                }
            }
        }

        if ($bar !== null) {
            $bar->finish();
            $this->newLine(2);
        }

        if (! $this->output->isQuiet()) {
            $this->line('  <fg=gray>────────────────────────────────────────</>');
        }

        $ignoreFilter     = $this->container->make(DeadcodeResultIgnoreFilter::class);
        $beforeUserIgnore = count($allResults);
        $allResults       = $ignoreFilter->filterResults($allResults);
        $userIgnored      = $beforeUserIgnore - count($allResults);

        if ($userIgnored > 0 && ! $this->output->isQuiet()) {
            $this->line(sprintf(
                '  <fg=gray>User ignore rules</> <fg=cyan>(config + %s)</> <fg=gray>hid</> <fg=white>%d</> <fg=gray>finding(s).</>',
                DeadcodeResultIgnoreFilter::INLINE_TAG,
                $userIgnored
            ));
            $this->line('');
        }

        if ($outputPath !== '') {
            try {
                if (ReportPayloadBuilder::isJsonExportPath($outputPath)) {
                    ReportPayloadBuilder::writeJsonFile($outputPath, $allResults, $phpFilesInScope);
                    $kind = 'JSON';
                } else {
                    PlainTextReportWriter::write($outputPath, $allResults);
                    $kind = 'text';
                }
                if (! $this->output->isQuiet()) {
                    $this->info(sprintf(
                        '  <fg=green>✓</> %s report written: <fg=white>%s</> <fg=gray>(%d finding(s))</>',
                        $kind,
                        $outputPath,
                        count($allResults)
                    ));
                    $this->line('');
                }
            } catch (Throwable $e) {
                $this->error('  Failed to write --output file: ' . $e->getMessage());
            }
        }

        $skipConsoleDetail = $onlySummary && $outputPath !== '';

        if ($format === 'console' && ! $skipConsoleDetail) {
            $reporter = new ConsoleReporter($this->output, $compact);
            $reporter->report($allResults);
        }

        if ($skipConsoleDetail && $allResults !== []) {
            $this->line(sprintf(
                '  <fg=yellow>Summary only:</> <fg=red;options=bold>%d</> item(s) — see <fg=white>%s</>',
                count($allResults),
                $outputPath
            ));
            $this->line('');
        }

        if ($format === 'json') {
            $reporter = new JsonReporter($this->output, $phpFilesInScope);
            $reporter->report($allResults);
        }

        if ($format === 'console' && ! $this->output->isQuiet()) {
            $this->renderClosingSummary($phpFilesInScope, $allResults);
        }

        if ($interactive) {
            if ($format !== 'console') {
                if (! $this->output->isQuiet()) {
                    $this->warn('  <fg=yellow>--interactive</> runs only with <fg=white>--format=console</> (not json).');
                    $this->line('');
                }
            } elseif ($allResults !== []) {
                InteractiveDeadcodeWorkflow::run($this, $allResults);
            } elseif (! $this->output->isQuiet()) {
                $this->line('  <fg=gray>Interactive:</> nothing to review (0 findings).');
                $this->line('');
            }
        }

        if ($errors !== []) {
            $this->line('');
            $this->warn('  <fg=yellow>Some analyzers failed:</>');
            foreach ($errors as $key => $message) {
                $this->line("    <fg=red>•</> [<fg=white>{$key}</>] {$message}");
            }
            $this->line('');
        }

        return self::SUCCESS;
    }

    private function renderHeader(): void
    {
        $this->line('');
        $this->line('  <fg=cyan;options=bold>Laravel Dead Code Detector</>');
        $this->line('  <fg=gray>Static analysis for unused controllers, views, routes, and more.</>');
        $this->line('');
    }

    /**
     * @param list<\Arafa\DeadcodeDetector\DTOs\DeadCodeResult> $results
     */
    private function renderClosingSummary(int $phpFilesInScope, array $results): void
    {
        $total = count($results);

        $by = [
            DetectionConfidence::HIGH    => 0,
            DetectionConfidence::MEDIUM => 0,
            DetectionConfidence::LOW    => 0,
        ];
        foreach ($results as $r) {
            if (isset($by[$r->confidenceLevel])) {
                ++$by[$r->confidenceLevel];
            }
        }

        $this->line('  <fg=gray>════════════════════════════════════════</>');
        $this->line('  <options=bold>Summary</>');
        $this->line('');
        $this->line(sprintf(
            '  <fg=gray>PHP files in merged scan scope (unique):</>  <fg=white>%s</>',
            number_format($phpFilesInScope)
        ));

        if ($total === 0) {
            $this->line('  <fg=gray>Possible dead-code findings:</>             <fg=green;options=bold>0  — workspace looks clean</>');
        } else {
            $this->line(sprintf(
                '  <fg=gray>Possible dead-code findings:</>             <fg=red;options=bold>%s</>',
                number_format($total)
            ));
            $this->line('');
            $this->line('  <fg=gray>By confidence</> <fg=gray>(review before deleting):</>');
            $this->line(sprintf(
                '    <fg=red>●</> High    %s  <fg=gray>%s</>',
                str_pad((string) $by[DetectionConfidence::HIGH], 5, ' ', STR_PAD_LEFT),
                DetectionConfidence::shortHintForLevel(DetectionConfidence::HIGH)
            ));
            $this->line(sprintf(
                '    <fg=yellow>●</> Medium  %s  <fg=gray>%s</>',
                str_pad((string) $by[DetectionConfidence::MEDIUM], 5, ' ', STR_PAD_LEFT),
                DetectionConfidence::shortHintForLevel(DetectionConfidence::MEDIUM)
            ));
            $this->line(sprintf(
                '    <fg=yellow>●</> Low     %s  <fg=gray>%s</>',
                str_pad((string) $by[DetectionConfidence::LOW], 5, ' ', STR_PAD_LEFT),
                DetectionConfidence::shortHintForLevel(DetectionConfidence::LOW)
            ));
        }

        $this->line('');
        $this->line('  <fg=gray>────────────────────────────────────────</>');
        $this->line('  <fg=green>Green</> clean  ·  <fg=yellow>Yellow</> caution  ·  <fg=red>Red</> findings (re-check with tests & VCS)');
        $this->line('');
    }

    /**
     * @return array<string, string>
     */
    private function resolveEnabledAnalyzers(): array
    {
        $configAnalyzers = config('deadcode.analyzers', []);
        $customAnalyzers = config('deadcode.custom_analyzers', []);
        $enabled         = [];

        foreach (self::BUILTIN_MAP as $key => $defaultClass) {
            $value = $configAnalyzers[$key] ?? false;

            if ($value === false) {
                continue;
            }

            $resolvedClass = (is_string($value) && class_exists($value))
                ? $value
                : $defaultClass;

            $enabled[$key] = $resolvedClass;
        }

        foreach ($customAnalyzers as $class) {
            if (class_exists($class)) {
                $enabled[$class] = $class;
            }
        }

        return $enabled;
    }
}
