<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use SplFileInfo;
use Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Arafa\DeadcodeDetector\Support\AstParserFactory;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;

class ViewsAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly PhpFileScanner $scanner,
        private readonly array $scanPaths,
        private readonly array $excludePaths = [],
    ) {}

    public function getName(): string
    {
        return 'views';
    }

    public function getDescription(): string
    {
        return 'Finds Blade view files that are never rendered or included anywhere.';
    }

    public function analyze(): array
    {
        $viewFiles = $this->findViewFiles();

        if ($viewFiles === []) {
            return [];
        }

        $referencedDots = $this->collectReferencedViewDotNames();

        $results = [];

        foreach ($viewFiles as $file) {
            $dotName = $this->toDotNotation($file);
            $last    = str_contains($dotName, '.')
                ? substr($dotName, strrpos($dotName, '.') + 1)
                : $dotName;

            if (! isset($referencedDots[$dotName]) && ! isset($referencedDots[$last])) {
                $results[] = DeadCodeResult::fromArray([
                    'analyzerName'   => $this->getName(),
                    'type'           => 'view',
                    'filePath'       => $file->getRealPath(),
                    'className'      => null,
                    'methodName'     => null,
                    'lastModified'   => date('Y-m-d H:i:s', $file->getMTime()),
                    'isSafeToDelete' => false,
                ]);
            }
        }

        return $results;
    }

    /**
     * @return array<string, true> Dot-notation view names
     */
    private function collectReferencedViewDotNames(): array
    {
        /** @var array<string, true> */
        $refs = [];

        $visitor = new ViewReferenceCollector();
        foreach ($this->iteratePhpSourcesForViews() as $path) {
            $stmts = $this->parseStatements($path);
            if ($stmts === null) {
                continue;
            }

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($visitor);
            $traverser->traverse($stmts);
        }

        foreach ($visitor->getDotNames() as $d => $_) {
            $refs[$d] = true;
        }

        foreach ($this->iterateBladeFiles() as $path) {
            $this->collectViewRefsFromBlade($path, $refs);
        }

        return $refs;
    }

    /**
     * @param array<string, true> $refs
     */
    private function collectViewRefsFromBlade(string $path, array &$refs): void
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            return;
        }

        $patterns = [
            '/@extends\s*\(\s*[\'"]([^\'"]+)[\'"]/',
            '/@include(?:If|When|Unless|First)?\s*\(\s*[\'"]([^\'"]+)[\'"]/',
            '/@component(?:First)?\s*\(\s*[\'"]([^\'"]+)[\'"]/',
            '/@each\s*\(\s*[\'"]([^\'"]+)[\'"]/',
            '/@include\s*\(\s*[\'"]([^\'"]+)[\'"]/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $m)) {
                foreach ($m[1] as $name) {
                    $refs[$this->normalizeDotName($name)] = true;
                }
            }
        }
    }

    private function normalizeDotName(string $name): string
    {
        return str_replace('/', '.', str_replace('\\', '/', $name));
    }

    /**
     * @return \Generator<string>
     */
    private function iteratePhpSourcesForViews(): \Generator
    {
        foreach ($this->scanPaths as $basePath) {
            foreach ($this->scanner->scanDirectory($basePath) as $file) {
                $real = $file->getRealPath();
                if ($real !== false && ! $this->isExcluded($real)) {
                    yield $real;
                }
            }
        }

        $routesDir = base_path('routes');
        if (is_dir($routesDir)) {
            foreach ($this->scanner->scanDirectory($routesDir) as $file) {
                $real = $file->getRealPath();
                if ($real !== false && ! $this->isExcluded($real)) {
                    yield $real;
                }
            }
        }
    }

    /**
     * @return \Generator<string>
     */
    private function iterateBladeFiles(): \Generator
    {
        $viewsDir = resource_path('views');
        if (! is_dir($viewsDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($viewsDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $real = $file->getRealPath();
                if ($real !== false && ! $this->isExcluded($real)) {
                    yield $real;
                }
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

    /** @return SplFileInfo[] */
    private function findViewFiles(): array
    {
        $found     = [];
        $viewsPath = resource_path('views');

        if (! is_dir($viewsPath)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($viewsPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $real = $file->getRealPath();
            if ($real !== false && ! $this->isExcluded($real)) {
                $found[] = $file;
            }
        }

        return $found;
    }

    /**
     * resources/views/auth/login.blade.php → auth.login
     */
    private function toDotNotation(SplFileInfo $file): string
    {
        $base = realpath(resource_path('views'));
        if ($base === false) {
            return '';
        }

        $base .= DIRECTORY_SEPARATOR;
        $relative = str_replace($base, '', $file->getRealPath() ?: '');
        $relative = str_replace(['/', '\\'], '.', $relative);

        return (string) preg_replace('/\.blade\.php$/', '', $relative);
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

/** Collects view names from view(), View::make, Route::view, etc. */
final class ViewReferenceCollector extends NodeVisitorAbstract
{
    /** @var array<string, true> */
    private array $dots = [];

    /**
     * @return array<string, true>
     */
    public function getDotNames(): array
    {
        return $this->dots;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof FuncCall) {
            $fn = $this->funcName($node);
            if ($fn === 'view' && isset($node->args[0]) && $node->args[0]->value instanceof String_) {
                $this->addDot($node->args[0]->value->value);
            }

            return null;
        }

        if ($node instanceof MethodCall) {
            $m = $this->identifierToString($node->name);
            if ($m === 'make' && isset($node->args[0]) && $node->args[0]->value instanceof String_) {
                $this->addDot($node->args[0]->value->value);
            }

            if ($m === 'view' && isset($node->args[1]) && $node->args[1]->value instanceof String_) {
                $this->addDot($node->args[1]->value->value);
            }

            return null;
        }

        if ($node instanceof StaticCall) {
            $m = $this->identifierToString($node->name);
            if (strtolower($m) === 'make' && isset($node->args[0]) && $node->args[0]->value instanceof String_) {
                $this->addDot($node->args[0]->value->value);
            }

            if (strtolower($m) === 'view' && isset($node->args[1]) && $node->args[1]->value instanceof String_) {
                $this->addDot($node->args[1]->value->value);
            }
        }

        return null;
    }

    private function addDot(string $name): void
    {
        $name = str_replace('/', '.', str_replace('\\', '/', trim($name)));
        if ($name !== '') {
            $this->dots[$name] = true;
        }
    }

    private function funcName(FuncCall $node): ?string
    {
        $n = $node->name;
        if ($n instanceof Name) {
            return $n->getLast();
        }

        if ($n instanceof Node\Name\FullyQualified) {
            return $n->getLast();
        }

        return null;
    }

    private function identifierToString(Node $name): string
    {
        if ($name instanceof Identifier) {
            return $name->name;
        }

        return '';
    }
}
