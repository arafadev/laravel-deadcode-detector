<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use SplFileInfo;
use Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Arafa\DeadcodeDetector\Support\AstParserFactory;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;

class MigrationsAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly PhpFileScanner $scanner,
        private readonly array $scanPaths,
        private readonly array $excludePaths = [],
    ) {}

    public function getName(): string
    {
        return 'migrations';
    }

    public function getDescription(): string
    {
        return 'Finds migration files that duplicate a table creation or have never been run.';
    }

    public function analyze(): array
    {
        $migrationFiles = $this->findMigrationFiles();

        if ($migrationFiles === []) {
            return [];
        }

        $results = [];

        $duplicates = $this->findDuplicateTableCreations($migrationFiles);
        foreach ($duplicates as $file) {
            $results[] = DeadCodeResult::fromArray([
                'analyzerName'   => $this->getName(),
                'type'           => 'migration',
                'filePath'       => $file->getRealPath(),
                'className'      => $this->extractClassName($file),
                'methodName'     => 'up',
                'lastModified'   => date('Y-m-d H:i:s', $file->getMTime()),
                'isSafeToDelete' => false,
            ]);
        }

        $emptyMigrations = $this->findEmptyMigrations($migrationFiles);
        foreach ($emptyMigrations as $file) {
            $path = $file->getRealPath();
            $already = array_filter(
                $results,
                static fn (DeadCodeResult $r) => $r->filePath === $path
            );

            if ($already !== []) {
                continue;
            }

            $results[] = DeadCodeResult::fromArray([
                'analyzerName'   => $this->getName(),
                'type'           => 'migration',
                'filePath'       => $path,
                'className'      => $this->extractClassName($file),
                'methodName'     => 'up',
                'lastModified'   => date('Y-m-d H:i:s', $file->getMTime()),
                'isSafeToDelete' => true,
            ]);
        }

        return $results;
    }

    /** @return SplFileInfo[] */
    private function findMigrationFiles(): array
    {
        $migrationsDir = database_path('migrations');

        if (! is_dir($migrationsDir)) {
            return [];
        }

        $files = [];
        foreach ($this->scanner->scanDirectory($migrationsDir) as $file) {
            $real = $file->getRealPath();
            if ($real !== false && ! $this->isExcluded($real)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @param  SplFileInfo[] $files
     * @return SplFileInfo[]
     */
    private function findDuplicateTableCreations(array $files): array
    {
        usort($files, static fn ($a, $b) => strcmp($a->getFilename(), $b->getFilename()));

        /** @var array<string, string> table => first file path */
        $seenTables = [];

        /** @var array<string, SplFileInfo> realPath => file */
        $duplicates = [];

        foreach ($files as $file) {
            $path = $file->getRealPath();
            if ($path === false) {
                continue;
            }

            $stmts = $this->parseStatements($path);
            if ($stmts === null) {
                continue;
            }

            $visitor = new SchemaCreateTableCollector();
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($visitor);
            $traverser->traverse($stmts);

            foreach ($visitor->getTableNames() as $table) {
                if (isset($seenTables[$table])) {
                    $duplicates[$path] = $file;
                } else {
                    $seenTables[$table] = $path;
                }
            }
        }

        return array_values($duplicates);
    }

    /**
     * @param  SplFileInfo[] $files
     * @return SplFileInfo[]
     */
    private function findEmptyMigrations(array $files): array
    {
        $empty = [];

        foreach ($files as $file) {
            $path = $file->getRealPath();
            if ($path === false) {
                continue;
            }

            $stmts = $this->parseStatements($path);
            if ($stmts === null) {
                continue;
            }

            $visitor = new EmptyMigrationUpVisitor();
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($visitor);
            $traverser->traverse($stmts);

            if ($visitor->isUpMethodEmptyOfSchemaDbCalls()) {
                $empty[] = $file;
            }
        }

        return $empty;
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

    private function extractClassName(SplFileInfo $file): ?string
    {
        $path = $file->getRealPath();
        if ($path === false) {
            return null;
        }

        $stmts = $this->parseStatements($path);
        if ($stmts === null) {
            return pathinfo($file->getFilename(), PATHINFO_FILENAME);
        }

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                foreach ($stmt->stmts ?? [] as $inner) {
                    if ($inner instanceof Class_ && $inner->name !== null) {
                        return $inner->name->name;
                    }
                }
            } elseif ($stmt instanceof Class_ && $stmt->name !== null) {
                return $stmt->name->name;
            }
        }

        return pathinfo($file->getFilename(), PATHINFO_FILENAME);
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

/** Detects duplicate Schema::create('table', ...) table names. */
final class SchemaCreateTableCollector extends NodeVisitorAbstract
{
    /** @var list<string> */
    private array $tables = [];

    /**
     * @return list<string>
     */
    public function getTableNames(): array
    {
        return $this->tables;
    }

    public function enterNode(Node $node): ?int
    {
        if (! $node instanceof StaticCall) {
            return null;
        }

        if (! $this->isMethodNamed($node->name, 'create')) {
            return null;
        }

        if (! $this->isSchemaClass($node->class)) {
            return null;
        }

        if (! isset($node->args[0])) {
            return null;
        }

        $arg = $node->args[0]->value ?? null;
        $table = $this->extractTableNameFromArg($arg);
        if ($table !== null) {
            $this->tables[] = $table;
        }

        return null;
    }

    private function isSchemaClass(?Node $class): bool
    {
        if ($class instanceof Name) {
            return $class->getLast() === 'Schema';
        }

        if ($class instanceof FullyQualified) {
            return $class->getLast() === 'Schema';
        }

        return false;
    }

    private function isMethodNamed(Node $name, string $expected): bool
    {
        if ($name instanceof Identifier) {
            return $name->name === $expected;
        }

        return false;
    }

    private function extractTableNameFromArg(?Node $arg): ?string
    {
        if ($arg instanceof String_) {
            return $arg->value;
        }

        if ($arg === null) {
            return null;
        }

        $printer = new PrettyPrinter();
        $printed = $printer->prettyPrintExpr($arg);

        if (preg_match('/[\'"]([a-zA-Z0-9_]+)[\'"]/', $printed, $m)) {
            return $m[1];
        }

        return null;
    }
}

/**
 * True when at least one up() exists and no StaticCall targets Schema or DB inside any up().
 */
final class EmptyMigrationUpVisitor extends NodeVisitorAbstract
{
    private bool $inUp = false;

    private bool $sawUp = false;

    private bool $hasSchemaOrDbStaticCall = false;

    public function isUpMethodEmptyOfSchemaDbCalls(): bool
    {
        return $this->sawUp && ! $this->hasSchemaOrDbStaticCall;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof ClassMethod && $node->name->name === 'up') {
            $this->inUp  = true;
            $this->sawUp = true;
        }

        if ($this->inUp && $node instanceof StaticCall && $this->isSchemaOrDbClass($node->class)) {
            $this->hasSchemaOrDbStaticCall = true;
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof ClassMethod && $node->name->name === 'up') {
            $this->inUp = false;
        }

        return null;
    }

    private function isSchemaOrDbClass(?Node $class): bool
    {
        if ($class instanceof Name) {
            $last = $class->getLast();

            return $last === 'Schema' || $last === 'DB';
        }

        if ($class instanceof FullyQualified) {
            $last = $class->getLast();

            return $last === 'Schema' || $last === 'DB';
        }

        return false;
    }
}
