# laravel-deadcode-detector 🧹🔎

Detect and report dead / unused code in your Laravel applications — controllers,
models, views, routes, middleware, migrations, and more.

---

## Requirements 🧰

| Laravel | PHP         |
|---------|-------------|
| 8.x     | 8.0 – 8.3   |
| 9.x     | 8.0 – 8.3   |
| 10.x    | 8.1 – 8.3   |
| 11.x    | 8.2 – 8.3   |
| 12.x    | 8.2 – 8.3   |

---

## Installation 🚀

```bash
composer require arafa/laravel-deadcode-detector
```

The service provider is auto-discovered. No manual registration needed.

### Publish the config file

```bash
php artisan vendor:publish --tag=deadcode-config
```

---

## Usage 🔍

```bash
# Detailed table output (default)
php artisan dead:scan --details

# Show one line per finding (less scrolling)
php artisan dead:scan --compact

# Save the full report to a text file (recommended for big projects)
php artisan dead:scan --output=storage/app/deadcode-full.txt

# With --output, skip tables in the terminal (only counts + file path)
php artisan dead:scan --output=storage/app/deadcode-full.txt --only-summary

# JSON output (pipe-friendly). Use this when you want JSON only.
php artisan dead:scan --format=json > report.json

# Interactive mode – confirm before marking files
php artisan dead:scan --interactive
```

---

## Configuration

After publishing, edit `config/deadcode.php`:

```php
return [
    // Toggle built-in analyzers on/off, or replace with a custom FQCN
    'analyzers' => [
        'controllers' => true,
        'models'      => true,
        'views'       => true,
        'routes'      => true,
        'middlewares' => true,
        'migrations'  => true,
    ],

    // Register your own analyzers
    'custom_analyzers' => [
        \App\DeadCode\UnusedJobsAnalyzer::class,
    ],

    // Directories to scan (defaults to app/ and resources/views)
    'scan_paths' => [
        app_path(),
        resource_path('views'),
    ],

    // Glob patterns to exclude
    'exclude_paths' => [
        'tests',
    ],
];
```

---

## Registering a Custom Analyzer

Implement `AnalyzerInterface`:

```php
use Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;

class UnusedJobsAnalyzer implements AnalyzerInterface
{
    public function getName(): string        { return 'unused-jobs'; }
    public function getDescription(): string { return 'Finds job classes never dispatched.'; }

    public function analyze(): array
    {
        // ... your logic ...
        return [
            DeadCodeResult::fromArray([
                'analyzerName' => $this->getName(),
                'type'         => 'job',
                'filePath'     => '/path/to/SendWelcomeEmail.php',
                'className'    => 'App\Jobs\SendWelcomeEmail',
            ]),
        ];
    }
}
```

Then add it to `config/deadcode.php`:

```php
'custom_analyzers' => [
    \App\DeadCode\UnusedJobsAnalyzer::class,
],
```

---

## Architecture

```
src/
├── Analyzers/Contracts/AnalyzerInterface.php   – contract every analyzer must satisfy
├── Reporters/Contracts/ReporterInterface.php   – contract for output formatters
├── Commands/DeadScanCommand.php                – artisan dead:scan entry point
├── Support/PhpFileScanner.php                  – recursive PHP file discovery
├── DTOs/DeadCodeResult.php                     – immutable value object for findings
└── DeadCodeServiceProvider.php                 – service provider / bootstrapper
config/
└── deadcode.php                                – user-facing configuration
```

### Design principles

- **No facades** inside the package core — dependencies are injected via the container.
- **Analyzer failures are isolated** — one broken analyzer never stops the rest.
- **Lazy file iteration** — `PhpFileScanner` yields `SplFileInfo` objects without
  loading file contents into memory.
- **SOLID** — each class has a single responsibility; analyzers and reporters are
  interchangeable via interfaces.

---

## License 📄

MIT
