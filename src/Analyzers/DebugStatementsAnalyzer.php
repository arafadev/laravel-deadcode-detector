<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use SplFileInfo;
use Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Arafa\DeadcodeDetector\Support\AstParserFactory;
use Arafa\DeadcodeDetector\Support\PathExcludeMatcher;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;

/**
 * Finds debug function calls like dd(), dump(), ray() scattered across the code.
 */
class DebugStatementsAnalyzer implements AnalyzerInterface
{
    /** @var list<string> */
    private array $debugFunctions;

    /**
     * @param list<string> $scanPaths
     */
    public function __construct(
        private PhpFileScanner $scanner,
        private array $scanPaths,
        private PathExcludeMatcher $pathExclude,
    ) {
        $this->debugFunctions = config('deadcode.debug_functions', [
            'dd', 'dump', 'ddd', 'ray', 'var_dump', 'print_r', 'console_log',
        ]);
    }

    public function getName(): string
    {
        return 'debug_statements';
    }

    public function getDescription(): string
    {
        return 'Finds leftover debugging function calls (e.g. dd, dump, ray) across the codebase.';
    }

    public function analyze(): array
    {
        $results = [];

        foreach ($this->iterateAllPhp() as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            if (str_ends_with($filePath, '.blade.php')) {
                $this->analyzeBladeFile($filePath, $results);
                continue;
            }

            $stmts = $this->parseStatements($filePath);
            if ($stmts === null) {
                continue;
            }

            $collector = new DebugStatementVisitor($filePath, $this->debugFunctions);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($collector);
            $traverser->traverse($stmts);

            foreach ($collector->getFindings() as $finding) {
                $results[] = DeadCodeResult::fromArray([
                    'analyzerName'   => $this->getName(),
                    'type'           => 'debug_statement',
                    'filePath'       => $finding['file'],
                    'className'      => $finding['className'],
                    'methodName'     => $finding['name'],
                    'lastModified'   => date('Y-m-d H:i:s', filemtime($finding['file']) ?: time()),
                    'isSafeToDelete' => true,
                    'confidenceLevel'=> 'high',
                    'reason'         => sprintf('Found debugging call `%s()` at line %d which should be removed before production.', $finding['name'], $finding['line']),
                ]);
            }
        }

        return $results;
    }

    /**
     * @param list<DeadCodeResult> &$results
     */
    private function analyzeBladeFile(string $filePath, array &$results): void
    {
        $code = @file_get_contents($filePath);
        if ($code === false || $code === '') {
            return;
        }

        $lines = explode("\n", $code);
        $namesPattern = implode('|', array_map(function ($name) {
            return preg_quote($name, '/');
        }, $this->debugFunctions));

        $pattern = '/(?:@|\b)(' . $namesPattern . ')\s*\(/';

        foreach ($lines as $index => $line) {
            if (preg_match_all($pattern, $line, $matches)) {
                $lineNumber = $index + 1;
                foreach ($matches[1] as $name) {
                    $results[] = DeadCodeResult::fromArray([
                        'analyzerName'   => $this->getName(),
                        'type'           => 'debug_statement',
                        'filePath'       => $filePath,
                        'className'      => null,
                        'methodName'     => $name,
                        'lastModified'   => date('Y-m-d H:i:s', filemtime($filePath) ?: time()),
                        'isSafeToDelete' => true,
                        'confidenceLevel'=> 'high',
                        'reason'         => sprintf('Found debugging call `%s()` at line %d which should be removed before production.', $name, $lineNumber),
                    ]);
                }
            }
        }
    }

    /**
     * @return \Generator<SplFileInfo>
     */
    private function iterateAllPhp(): \Generator
    {
        $seen = [];
        foreach ($this->scanPaths as $basePath) {
            foreach ($this->scanner->scanDirectoryLazy($basePath) as $file) {
                $real = $file->getRealPath();
                if ($real === false || isset($seen[$real]) || $this->pathExclude->shouldExclude($real)) {
                    continue;
                }
                $seen[$real] = true;
                yield $file;
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
}

final class DebugStatementVisitor extends NodeVisitorAbstract
{
    /** @var list<array{file: string, line: int, name: string, className: ?string}> */
    private array $findings = [];

    private ?string $currentNamespace = null;
    private ?string $currentClass = null;

    /**
     * @param list<string> $targetFunctions
     */
    public function __construct(
        private string $filePath,
        private array $targetFunctions,
    ) {}

    /**
     * @return list<array{file: string, line: int, name: string, className: ?string}>
     */
    public function getFindings(): array
    {
        return $this->findings;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name !== null ? $node->name->toString() : null;
        } elseif ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = $node->name !== null ? $node->name->name : null;
        }
        if ($node instanceof FuncCall) {
            if ($node->name instanceof Node\Name) {
                $name = $node->name->toString();
                if (in_array($name, $this->targetFunctions, true)) {
                    $this->findings[] = [
                        'file' => $this->filePath,
                        'name' => $name,
                        'line' => $node->getStartLine(),
                        'className' => $this->getFqcn(),
                    ];
                }
            }
        } elseif ($node instanceof StaticCall) {
            if ($node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
                $className = $node->class->toString();
                $methodName = $node->name->toString();
                $fullName = $className . '::' . $methodName;

                if (in_array($fullName, $this->targetFunctions, true)) {
                    $this->findings[] = [
                        'file' => $this->filePath,
                        'name' => $fullName,
                        'line' => $node->getStartLine(),
                        'className' => $this->getFqcn(),
                    ];
                }
            }
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = null;
        } elseif ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = null;
        }

        return null;
    }

    private function getFqcn(): ?string
    {
        if ($this->currentClass === null) {
            return null;
        }

        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            return $this->currentNamespace . '\\' . $this->currentClass;
        }

        return $this->currentClass;
    }
}
