<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use SplFileInfo;
use Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Arafa\DeadcodeDetector\Support\AstParserFactory;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;

/**
 * Finds global / namespaced helper functions that are never called.
 */
class HelpersAnalyzer implements AnalyzerInterface
{
    /**
     * @param list<string>          $scanPaths
     * @param list<string>          $excludePaths
     * @param list<string> $helperFilePaths Absolute paths to PHP files that define helpers (merged with composer autoload files)
     */
    public function __construct(
        private readonly PhpFileScanner $scanner,
        private readonly array $scanPaths,
        private readonly array $excludePaths,
        private readonly array $helperFilePaths = [],
    ) {}

    public function getName(): string
    {
        return 'helpers';
    }

    public function getDescription(): string
    {
        return 'Finds helper functions (typically in helpers.php / composer autoload files) that are never called.';
    }

    public function analyze(): array
    {
        $definitionFiles = $this->resolveHelperDefinitionFiles();
        if ($definitionFiles === []) {
            return [];
        }

        /** @var list<array{fqfn: string, file: string, name: string}> $functions */
        $functions = [];

        foreach ($definitionFiles as $filePath) {
            $stmts = $this->parseStatements($filePath);
            if ($stmts === null) {
                continue;
            }

            $collector = new HelperFunctionDefinitionCollector();
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($collector);
            $traverser->traverse($stmts);

            foreach ($collector->getDefinitions() as $def) {
                $functions[] = [
                    'fqfn' => $def['fqfn'],
                    'file' => $filePath,
                    'name' => $def['name'],
                ];
            }
        }

        if ($functions === []) {
            return [];
        }

        $referenced = $this->collectReferencedFunctionCalls();

        $results = [];
        foreach ($functions as $fn) {
            $key = strtolower($fn['fqfn']);
            if (isset($referenced[$key])) {
                continue;
            }

            $results[] = DeadCodeResult::fromArray([
                'analyzerName'   => $this->getName(),
                'type'           => 'helper',
                'filePath'       => $fn['file'],
                'className'      => null,
                'methodName'     => $fn['fqfn'],
                'lastModified'   => date('Y-m-d H:i:s', filemtime($fn['file']) ?: time()),
                'isSafeToDelete' => false,
            ]);
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function resolveHelperDefinitionFiles(): array
    {
        $paths = $this->helperFilePaths;

        $composerJson = base_path('composer.json');
        if (is_file($composerJson)) {
            $json = json_decode((string) file_get_contents($composerJson), true);
            if (is_array($json)) {
                $files = $json['autoload']['files'] ?? [];
                foreach ((array) $files as $rel) {
                    if (! is_string($rel)) {
                        continue;
                    }
                    $abs = base_path($rel);
                    if (is_file($abs) && ! $this->isExcluded($abs)) {
                        $paths[] = $abs;
                    }
                }
            }
        }

        $defaults = [
            base_path('app/helpers.php'),
            base_path('bootstrap/helpers.php'),
            app_path('helpers.php'),
            app_path('Support/helpers.php'),
        ];

        foreach ($defaults as $p) {
            if (is_file($p) && ! $this->isExcluded($p)) {
                $paths[] = $p;
            }
        }

        $paths = array_values(array_unique(array_filter($paths)));

        return $paths;
    }

    /**
     * Includes helper definition files so internal helper→helper calls count as used.
     *
     * @return array<string, true> lowercased fqfn => true
     */
    private function collectReferencedFunctionCalls(): array
    {
        /** @var array<string, true> */
        $refs = [];

        $collector = new HelperFunctionCallCollector();
        foreach ($this->iterateAllPhpForCallScan() as $path) {
            $stmts = $this->parseStatements($path);
            if ($stmts === null) {
                continue;
            }

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($collector);
            $traverser->traverse($stmts);
        }

        foreach ($collector->getCalledFqfn() as $k => $_) {
            $refs[$k] = true;
        }

        return $refs;
    }

    /**
     * @return \Generator<string>
     */
    private function iterateAllPhpForCallScan(): \Generator
    {
        foreach ($this->scanPaths as $basePath) {
            foreach ($this->scanner->scanDirectory($basePath) as $file) {
                $real = $file->getRealPath();
                if ($real === false || $this->isExcluded($real)) {
                    continue;
                }
                yield $real;
            }
        }

        foreach (['routes', 'config', 'database'] as $dir) {
            $dirPath = base_path($dir);
            if (! is_dir($dirPath)) {
                continue;
            }
            foreach ($this->scanner->scanDirectory($dirPath) as $file) {
                $real = $file->getRealPath();
                if ($real === false || $this->isExcluded($real)) {
                    continue;
                }
                yield $real;
            }
        }
    }

    /**
     * @return \PhpParser\Node\Stmt[]|null
     */
    private function parseStatements(string $path): ?array
    {
        $code = @file_get_contents($path);
        if ($code === false) {
            return null;
        }

        try {
            return AstParserFactory::createParser()->parse($code);
        } catch (Error $e) {
            return null;
        }
    }

    private function isExcluded(string $path): bool
    {
        foreach ($this->excludePaths as $exclude) {
            if (str_contains($path, $exclude)) {
                return true;
            }
        }

        return false;
    }
}

/**
 * @phpstan-type Def array{fqfn: string, name: string}
 */
final class HelperFunctionDefinitionCollector extends NodeVisitorAbstract
{
    private ?string $namespacePrefix = null;

    /** @var list<Def> */
    private array $defs = [];

    /**
     * @return list<Def>
     */
    public function getDefinitions(): array
    {
        return $this->defs;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Namespace_) {
            $this->namespacePrefix = $node->name !== null ? $node->name->toString() . '\\' : '';

            return null;
        }

        if ($node instanceof Function_) {
            $name = $node->name->name;
            $fqfn = ($this->namespacePrefix ?? '') . $name;
            $this->defs[] = ['fqfn' => $fqfn, 'name' => $name];
        }

        return null;
    }
}

/** Records fully-qualified function names from FuncCall nodes (after NameResolver). */
final class HelperFunctionCallCollector extends NodeVisitorAbstract
{
    /** @var array<string, true> */
    private array $called = [];

    /**
     * @return array<string, true>
     */
    public function getCalledFqfn(): array
    {
        return $this->called;
    }

    public function enterNode(Node $node): ?int
    {
        if (! $node instanceof FuncCall) {
            return null;
        }

        $n = $node->name;
        if ($n instanceof FullyQualified) {
            $this->called[strtolower($n->toString())] = true;
        } elseif ($n instanceof Name) {
            $this->called[strtolower($n->toString())] = true;
        }

        return null;
    }
}
