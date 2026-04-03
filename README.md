# Laravel Dead Code Detector

[![Latest Stable Version](https://poser.pugx.org/arafa/laravel-deadcode-detector/v)](https://packagist.org/packages/arafa/laravel-deadcode-detector)
[![PHP Version Require](https://poser.pugx.org/arafa/laravel-deadcode-detector/require/php)](https://packagist.org/packages/arafa/laravel-deadcode-detector)
[![License](https://poser.pugx.org/arafa/laravel-deadcode-detector/license)](https://packagist.org/packages/arafa/laravel-deadcode-detector)

Static analysis for **Laravel** applications. It walks configurable PHP and Blade roots, runs **domain-specific analyzers**, and shares cross-cutting signals (including a **dependency graph** for controllers, routes, views, jobs, and more). Reports are **hints**, not proof: dynamic frameworks, reflection, stringly-typed references, and code outside the scan scope can produce false positives or lower confidence.

---

## Table of contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Command: `dead:scan`](#command-deadscan)
- [How scanning works](#how-scanning-works)
- [Outputs](#outputs)
- [Configuration](#configuration)
- [Built-in analyzers](#built-in-analyzers)
- [Important heuristics](#important-heuristics)
- [Reducing noise](#reducing-noise)
- [Interactive workflow](#interactive-workflow)
- [JSON export & CI](#json-export--ci)
- [Custom analyzers](#custom-analyzers)
- [Architecture](#architecture)
- [License](#license)

---

## Features

- **Many Laravel-focused categories**: controllers (and unrouted actions), models, Eloquent `scope*`, views/Blade reachability, named routes, middleware, migrations (duplicates / not run), helpers, form requests, API resources, policies, actions, services, Artisan commands, notifications, mailables, validation rules, enums, jobs, events & listeners, observers, container bindings.
- **Merged scan paths** with optional **auto-discovery** (`app/`, `routes`, `resources`, `config`, `database`, `composer.json` PSR-4 roots, etc.).
- **Path excludes** (`vendor`, `node_modules`, `storage`, …) plus your **`exclude_paths`** (`fnmatch` supported).
- **Post-filter** via config **`ignore`** or file-wide **`@deadcode-ignore`** (PHP / Blade).
- **Console** (compact default table, optional `--details` / `--compact`), **JSON**, and **plain-text** exports.
- **Optional interactive** pass: review, delete (safeguarded), or inject ignore markers.
- **Per-analyzer failure isolation**: one broken analyzer does not stop the rest.

---

## Requirements

| Component | Version |
|-----------|---------|
| PHP | `^8.0` |
| Laravel (`illuminate/support`, `console`, `contracts`) | `^8.0` … `^12.0` |
| Parser | `nikic/php-parser` `^4.15 \| ^5.0` |

Typical Laravel minimum PHP versions: **8.0** (Laravel 8–9), **8.1** (10), **8.2+** (11–12).

---

## Installation

```bash
composer require arafa/laravel-deadcode-detector
```

The package auto-registers via `extra.laravel.providers`.

Publish configuration (recommended):

```bash
php artisan vendor:publish --tag=deadcode-config
```

---

## Quick start

```bash
php artisan dead:scan
```

At normal verbosity you get a **progress bar**, per-category **findings tables** (default: **Type · File · Location**), optional lines for **user ignores**, and a **closing summary** (counts by confidence).

```bash
php artisan dead:scan -v          # log each analyzer name (no progress bar)
php artisan dead:scan -q          # minimal noise
php artisan dead:scan --details   # wider console table with short “why”
php artisan dead:scan --compact # one line per finding
```

---

## Command: `dead:scan`

| Option | Description |
|--------|-------------|
| `--format=console` | Default: human-readable tables. |
| `--format=json` | Single JSON document on **stdout** only (do not mix with normal console report). |
| `--output=path` | Write a file: **`.json`** → same schema as `--format=json`; any other extension → UTF-8 **plain-text** narrative report. |
| `--only-summary` | With `--output`, skip detailed console listing (short message + path to export). |
| `--details` | Extra columns in the console (class, method, modified, confidence, short reason). |
| `--compact` | One line per finding: `file \| type \| location`. |
| `--interactive` | After the scan (TTY + `console` only): per-finding **Delete** / **Ignore** (inject marker) / **Skip**. |
| `-v` / `-vv` | Verbose analyzer logging. |
| `-q` | Quiet. |

**Console UX:** the default layout avoids a **“safe to delete”** column (it wraps badly in narrow terminals). That flag still exists in **JSON** and related APIs for tooling.

**Exit codes:** `dead:scan` currently returns **success (0)** even when findings exist (and even when some analyzers fail, those are listed at the end). For CI gates, **parse JSON** (or grep output) and assert `summary.total_findings === 0` yourself.

---

## How scanning works

1. **Global roots** are built by `ScanPathResolver::globalScanPaths()` in order:  
   **auto-discovery** (if enabled) → **`scan_paths`** → **`paths.extra`** → fallback **`app_path()`** if nothing is configured.
2. **Per-analyzer roots**: if **`analyzer_paths.{key}`** is set, it defines that analyzer’s primary folders; **global roots are still merged** so references elsewhere in the app stay visible.
3. **Exclusions**: built-in skips (`vendor`, `node_modules`, `storage`, `bootstrap/cache`, `.git`, …) plus **`exclude_paths`**.
4. **PHP** is parsed with **nikic/php-parser** on each run (**no AST cache** under `storage`).
5. **Blade** is scanned for includes/extends/`@include`/`@extends` where applicable; view reachability also uses **`ViewReferenceCollector`** (see [Important heuristics](#important-heuristics)).
6. Results pass through **`DeadcodeResultIgnoreFilter`** (config + inline markers).

---

## Outputs

### Console

- Default: small table per finding **category** (`Type`, `File`, `Location`).
- Footer tip points to `--details`, `--output`, `--format=json`.

### JSON (`schema_version: 1`)

Produced by `ReportPayloadBuilder`:

- `generated_at`, `summary` (`total_findings`, `by_type`, `by_confidence`, `confidence_legend`, optional `php_files_in_scope`)
- `findings`: array of `DeadCodeResult::toArray()` rows (includes `fix_suggestions`, `confidence_level`, `is_safe_to_delete`, etc.)
- `by_type`: grouped copy of findings

### Plain text (`--output=*.txt`)

Human-readable boxes and sections. The redundant **“safe to delete”** row is omitted in the box layout; use **JSON** if you need that field in the export file.

---

## Configuration

All keys live in **`config/deadcode.php`** (see inline comments there). Summary:

| Key | Purpose |
|-----|---------|
| `auto_discover` | `true` (default): discover standard app roots + PSR-4 from `composer.json`. Env: **`DEADCODE_AUTO_DISCOVER`**. |
| `analyzers` | Map analyzer key → `true`, `false`, or replacement **FQCN** class. |
| `analyzer_paths` | Override primary directories **per analyzer key**; globals still merge. |
| `helper_paths` | Extra directories for the **helpers** analyzer. |
| `paths.extra` | Additional global roots (e.g. modules). |
| `scan_paths` | Extra global roots (especially when `auto_discover` is off). |
| `exclude_builtin` | Enable built-in path excludes. Env: **`DEADCODE_EXCLUDE_BUILTIN`**. |
| `exclude_paths` | Your globs / paths (`fnmatch`). **Files are not traversed** → invisible to graph and analyzers. |
| `ignore` | **`classes`**, **`folders`**, **`patterns`** — remove matching **findings** after analysis (files still analyzed for others). |
| `custom_analyzers` | List of `AnalyzerInterface` FQCNs. |

---

## Built-in analyzers

| Key | What it looks for |
|-----|-------------------|
| `controllers` | Unused controller classes; public actions not bound in routes. |
| `models` | Unused Eloquent models; unused local **`scope*`** methods. |
| `views` | Blade files never reached from PHP/Blade graph. |
| `routes` | Named routes never referenced (`route()`, `@route`, etc.). |
| `middlewares` | Middleware not registered / applied in scanned routes & kernel. |
| `migrations` | Duplicate `Schema::create` table names; migrations not recorded as run. |
| `helpers` | Functions in autoloaded helper files never called. |
| `requests` | `FormRequest` never type-hinted on actions / route callables. |
| `resources` | `JsonResource` / `ResourceCollection` never constructed. |
| `policies` | Policies not registered / referenced in scanned code. |
| `actions` | Action-style classes never executed. |
| `services` | Service-like classes never `new` / DI / `app()` / `::class` / **static calls** / bindings. |
| `commands` | Artisan `Command` subclasses not registered in kernel / `withCommands()`. |
| `notifications` | Notifications never sent in scanned code. |
| `mailables` | Mailables never queued/sent via `Mail` in scanned code. |
| `rules` | Custom `Rule` classes never instantiated. |
| `enums` | PHP enums never referenced. |
| `jobs` | Queueable jobs never dispatched (graph + patterns). |
| `events` | Event classes never dispatched / listed in `EventServiceProvider` `$listen` keys. |
| `listeners` | Listeners not listed in `$listen`. |
| `observers` | Observers under conventions not registered via `Model::observe()` etc. |
| `service_bindings` | Dubious container bindings / abstractions never resolved. |

Disable any key with `false` in `analyzers`.

---

## Important heuristics

These details explain common **false positives** and what is intentionally detected.

### Events

Counts dispatch when scanned code uses **`event(...)`**, **`Event::dispatch(...)`**, **`::dispatch` / `->dispatch`** on the event class, and **`broadcast(new YourEvent(...))`** (same shapes as `event`). Class names are resolved **after** the AST pass so `use`-imported `new YourEvent` matches the event FQCN. Events passed only through **variables** or built outside scan paths may still look “unused”.

### Models — `scope*`

Laravel’s public name for `scopeFooBar` is **`lcfirst`** of the suffix after `scope`. Usage is detected for **`MethodCall`** with that name (e.g. `$model->isInStock()`, chained `->inStock()`) and **`StaticCall`** on the model (`Product::inStock()`). Dynamic **`$model->{$name}()`** is not resolved statically.

### Services

A service candidate is “used” if scanned code has **`new`**, **`app(Class::class)`**, **parameter type hints**, **`Class::method()`**, **`Class::class`**, or the class appears as a **concrete** in `bind` / `singleton` / `scoped` / `instance` in scanned **`app/Providers`**.

### Views

Beyond **`view()`**, **`View::make`**, **`Route::view`**, Inertia helpers, and Blade chains, PHP scanning includes **mailable `->view('name')` / `->view('name', $data)`** (first argument = template) and **`new Content(view: 'name')`** (`Illuminate\Mail\Mailables\Content`). **`Route::view`**: if the first argument is a string containing **`/`**, the second is the view; if the first is not a string literal but the second is, the second is treated as the view (dynamic URI).

### Confidence

**High / medium / low** describe how strong the **static** signal is—not “safe to delete.” Some finding types may attach **`possible_dynamic_hint`** when names or paths are only partially known.

---

## Reducing noise

| Tool | Effect |
|------|--------|
| **`exclude_paths`** | Do not walk those paths → files **missing** from graph and analyzers. |
| **`ignore` (`classes` / `folders` / `patterns`)** | Strip matching **findings** only; other rules still see the files. |
| **Inline `// @deadcode-ignore`** or **`# …`** (PHP) / **`{{-- @deadcode-ignore --}}`** (Blade) | File-wide suppression for reporting. |

Prefer **`ignore`** over **`exclude_paths`** when the file should still participate in reference resolution for the rest of the app.

---

## Interactive workflow

With **`--interactive`** (console + TTY):

- **Delete**: only under `base_path()`, not under `vendor`, `node_modules`, `storage`, `bootstrap/cache`, `.git`; double confirmation; not offered for shared artifacts like pure **route** / **binding** rows when unsafe.
- **Ignore**: prepends the standard marker for writable `.php` / `.blade.php`.
- **Skip**: no change.

---

## JSON export & CI

```bash
php artisan dead:scan --format=json > deadcode-report.json
# or
php artisan dead:scan --output=storage/app/deadcode-report.json
```

Inspect `summary.total_findings` and `findings[]` in your pipeline. Remember the **exit code stays 0** unless you add your own wrapper script.

---

## Custom analyzers

Implement `Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface` (`getName()`, `getDescription()`, `analyze()` returning `DeadCodeResult[]`). Register FQCNs in **`custom_analyzers`**. The service provider binds **`PhpFileScanner`**, merged scan paths, and **`PathExcludeMatcher`**.

You can also replace a built-in by setting `analyzers.{key}` to your class FQCN.

---

## Architecture

| Area | Role |
|------|------|
| `DeadScanCommand` | Orchestrates analyzers, ignore filter, reporters, exports, interactive mode. |
| `Analyzers/*` | Per-domain detection implementing `AnalyzerInterface`. |
| `Support/DependencyGraphEngine`, `ProjectPhpIterator`, `PathExcludeMatcher`, `PhpAstParser` | Graph, filesystem iteration, exclusions, parse-on-demand. |
| `Support/ViewReferenceCollector` | View/Inertia/Mailable view name extraction for the graph. |
| `DeadcodeResultIgnoreFilter`, `DeadcodeInlineIgnoreMarker` | Config + inline suppression; marker insertion. |
| `ReportPayloadBuilder`, `PlainTextReportWriter`, `ConsoleReporter`, `JsonReporter` | Output channels. |
| `DeadCodeResult`, `DetectionConfidence`, `FindingFixSuggestion` | Finding model and UX metadata. |

---

## License

MIT
