# Laravel Dead Code Detector

Static analysis for Laravel applications. It scans PHP and Blade under configurable roots, combines per-analyzer logic with cross-file signals (including a dependency graph), and reports **possible** unused code: controllers, models, views, named routes, middleware, migrations, jobs, service bindings, and many other Laravel-oriented categories.

**Findings are hints, not proof.** Each row includes a **reason**, **confidence** (high / medium / low), and **fix suggestions** (review first, when deletion might be reasonable, and how to ignore safely). Always use tests, review, and version control before removing anything.

---

## Requirements

- **PHP:** `^8.0` (`composer.json`).
- **Illuminate** (`support`, `console`, `contracts`): `^8.0` … `^12.0`.

Typical Laravel PHP floors:

| Laravel     | PHP (usual minimum) |
|-------------|---------------------|
| 8.x – 9.x   | 8.0+                |
| 10.x        | 8.1+                |
| 11.x – 12.x | 8.2+                |

---

## Installation

```bash
composer require arafa/laravel-deadcode-detector
```

The package registers via Laravel’s auto-discovery (`extra.laravel.providers`).

Publish the configuration:

```bash
php artisan vendor:publish --tag=deadcode-config
```

---

## Quick start

```bash
php artisan dead:scan
```

You get analyzer progress (by default a progress bar at normal verbosity), a **console report** (tables or `--compact` one-liners), a line summarizing **config/inline ignores** when applicable, optional **file export**, and a **closing summary** (including counts by confidence).

```bash
php artisan dead:scan -v    # list each analyzer without the progress bar
php artisan dead:scan -q   # quieter output
```

---

## Command: `dead:scan`

| Option | Description |
|--------|-------------|
| `--format=console` | Human-readable report (default). |
| `--format=json` | Emit a single JSON document on stdout (do not mix with normal table output). |
| `--output=path` | Write a report file. If the path ends in `.json`, writes the same structured JSON as `--format=json`. Otherwise writes UTF-8 **plain text** with full fields (no terminal truncation). |
| `--only-summary` | With `--output`, skip large console tables (short message + path to the saved report). |
| `--compact` | One line per finding in the console tables. |
| `--interactive` | After the scan summary (**console + interactive TTY only**): for each finding choose **Delete** (double-confirmed; safe paths only), **Ignore** (prepend `// @deadcode-ignore` or Blade `{{-- @deadcode-ignore --}}`), or **Skip** (default). Ignored when `--format=json`. |
| `-v` / `-vv` | Verbose analyzer output. |
| `-q` | Quiet. |

`--details` is kept for compatibility; detailed console output is already the default.

---

## Reports and outputs

### Console

- Tables (or compact lines) with file, class, method/symbol, modification time, confidence, **context hint**, **why**, **next steps**, and “safe to delete” flag when the analyzer sets it.
- If `config/deadcode.php` `ignore` or inline `@deadcode-ignore` removed items, a short line reports how many findings were hidden.

### JSON (`--format=json` or `--output=…json`)

Built by `ReportPayloadBuilder` (`schema_version: 1`):

- `generated_at`, `summary` (`total_findings`, `by_type`, `by_confidence`, `confidence_legend`, optional `php_files_in_scope`)
- `findings`: list of `DeadCodeResult::toArray()` rows
- `by_type`: the same rows grouped by `type`

Each finding includes `file_path`, `type`, `reason` / `why`, `confidence_level`, `confidence_hint`, class/method/analyzer metadata, `is_safe_to_delete`, and **`fix_suggestions`** (`context_hint` + `actions` with review/delete/ignore text).

### Plain text (`--output=report.txt` / e.g. `storage/app/deadcode-full.txt`)

Same fields as JSON in a **UTF-8** report with spacing, box drawing, and emoji section cues (easy to skim in an editor).

---

## Confidence and false positives

- **Confidence** = strength of the **static** signal, not “OK to delete.”
- Dynamic Laravel usage (container resolution, config-driven class names, reflection, code outside scan roots) can lower confidence or trigger “possible dynamic” explanations.
- Tune noise with **`ignore`** (post-analysis filter), **`@deadcode-ignore`** in the file, or **`exclude_paths`** (skip traversing paths entirely).

### `exclude_paths` vs `ignore`

| Mechanism | Effect |
|-----------|--------|
| `exclude_paths` (+ built-in excludes) | Files under those paths are **not walked**; they are invisible to the graph. |
| `ignore` (classes / folders / patterns) | Files are still part of analysis; matching **findings are removed** from the report so other items stay accurate. |

PHP files are parsed on each run (no AST cache under `storage`).

---

## Inline ignore (file-wide)

Recognized in sources (see `DeadcodeResultIgnoreFilter`):

- **PHP:** `// @deadcode-ignore` or `# …`, or the tag inside a block/doc comment near the top.
- **Blade:** `{{-- @deadcode-ignore --}}`

Applies to the **whole file** for reporting. Optional **interactive “Ignore”** uses `DeadcodeInlineIgnoreMarker` to insert this for `.php` / `.blade.php`.

---

## Configuration highlights (`config/deadcode.php`)

| Key | Role |
|-----|------|
| `auto_discover` | Discover `app/`, children of `app`, `routes`, `bootstrap`, `resources`, `config`, `database`, optional `src/`, and PSR-4 paths from `composer.json`. |
| `analyzers` | Toggle built-ins or set a replacement **FQCN** per key. |
| `analyzer_paths` | Override/extend scan roots **per analyzer** (global roots still merge in). |
| `helper_paths` | Extra roots for helpers. |
| `paths.extra` / `scan_paths` | Additional global roots. |
| `exclude_builtin` / `exclude_paths` | Path pruning (`fnmatch` supported on user entries). |
| `ignore` | `classes`, `folders`, `patterns` — strip findings after analysis. |
| `custom_analyzers` | Extra `AnalyzerInterface` classes. |

Full behavior is documented in the published config file comments.

---

## Built-in analyzer keys

`controllers`, `models`, `views`, `routes`, `middlewares`, `migrations`, `helpers`, `requests`, `resources`, `policies`, `actions`, `services`, `commands`, `notifications`, `mailables`, `rules`, `enums`, `jobs`, `events`, `listeners`, `observers`, `service_bindings`.

---

## Custom analyzers

Implement `Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface`: `getName()`, `getDescription()`, `analyze()` returning a list of `DeadCodeResult` (typically `DeadCodeResult::fromArray([...])` with optional `reason`, `confidenceLevel`, `isSafeToDelete`, `possibleDynamicHint`, etc.).

Register under `custom_analyzers`. The provider injects `PhpFileScanner`, merged paths, and `PathExcludeMatcher`.

---

## Interactive cleanup

- **Delete:** Not offered for types tied to **shared files** (`route`, `binding`). Otherwise only files **under `base_path()`**, not under `vendor`, `node_modules`, `storage`, `bootstrap/cache`, or `.git`, with **two** `no`-default confirmations before `unlink`. Extra warning when the finding may refer to only part of a file (`controller_method`, `model_scope`, `helper`).
- **Ignore:** Prepends the standard marker when the file is writable `.php` / `.blade.php`.
- **Skip:** No changes.

Requires an interactive terminal (`InputInterface::isInteractive()`).

---

## Architecture (overview)

| Piece | Responsibility |
|-------|------------------|
| `DeadScanCommand` | Runs analyzers, applies user ignore filter, writes exports, reporters, optional interactive workflow. |
| `Analyzers/*` | Per-domain dead/unused detection. |
| `Support/DependencyGraphEngine`, `ProjectPhpIterator`, `PathExcludeMatcher`, `PhpAstParser` | Graph, iteration, on-demand PHP parse (no AST cache). |
| `DeadcodeResultIgnoreFilter`, `DeadcodeInlineIgnoreMarker` | Config/inline suppression and interactive marker insertion. |
| `InteractiveDeadcodeWorkflow` | Delete / ignore / skip prompts. |
| `ReportPayloadBuilder`, `PlainTextReportWriter`, `ConsoleReporter`, `JsonReporter` | Outputs. |
| `DeadCodeResult`, `FindingFixSuggestion`, `DetectionConfidence` | Finding model, export shape, suggestions, confidence. |

One failed analyzer does not stop the rest; failures are summarized at the end of the command.

---

## License

MIT
