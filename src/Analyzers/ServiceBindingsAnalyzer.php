<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\UnionType;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Arafa\DeadcodeDetector\Support\AstParserFactory;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;
use Arafa\DeadcodeDetector\Support\PathExcludeMatcher;
use Arafa\DeadcodeDetector\Support\ProjectPhpIterator;

class ServiceBindingsAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly PhpFileScanner $scanner,
        private readonly array $scanPaths,
        private readonly PathExcludeMatcher $pathExclude,
    ) {}

    /**
     * @return list<string>
     */
    public static function defaultScanPaths(): array
    {
        if (! function_exists('app_path')) {
            return [];
        }

        return [app_path('Providers')];
    }

    public function getName(): string
    {
        return 'service_bindings';
    }

    public function getDescription(): string
    {
        return 'Finds invalid container bindings and abstractions that appear never resolved.';
    }

    public function analyze(): array
    {
        $classMap = $this->buildDeclaredClassMap();
        $bindings = $this->extractBindingsFromProviders();
        if ($bindings === []) {
            return [];
        }

        $referencedAbstracts = $this->collectAbstractUsageNorms();

        $results = [];
        foreach ($bindings as $b) {
            $absNorm = $b['abstractNorm'];
            $conNorm = $b['concreteNorm'];
            $absFqcn = $b['abstractFqcn'];
            $conFqcn = $b['concreteFqcn'];
            $file    = $b['file'];

            if (! isset($classMap[$conNorm])) {
                $results[] = DeadCodeResult::fromArray([
                    'analyzerName'   => $this->getName(),
                    'type'           => 'binding',
                    'filePath'       => $file,
                    'className'      => $conFqcn,
                    'methodName'     => $b['method'] . ':missing_concrete',
                    'lastModified'   => date('Y-m-d H:i:s', filemtime($file) ?: time()),
                    'isSafeToDelete' => false,
                ]);

                continue;
            }

            if (! isset($referencedAbstracts[$absNorm])) {
                $results[] = DeadCodeResult::fromArray([
                    'analyzerName'        => $this->getName(),
                    'type'                => 'binding',
                    'filePath'            => $file,
                    'className'           => $absFqcn,
                    'methodName'          => $b['method'] . ':abstract_never_resolved',
                    'lastModified'        => date('Y-m-d H:i:s', filemtime($file) ?: time()),
                    'isSafeToDelete'      => false,
                    'possibleDynamicHint' => true,
                ]);
            }
        }

        return $results;
    }

    /**
     * @return array<string, true>
     */
    private function buildDeclaredClassMap(): array
    {
        $map = [];
        foreach (ProjectPhpIterator::iterate($this->scanner, $this->scanPaths, $this->pathExclude) as $path) {
            $fqcn = $this->extractOneClassFqcnFromFile($path);
            if ($fqcn !== null) {
                $map[$this->norm($fqcn)] = true;
            }
        }

        return $map;
    }

    /**
     * @return list<array{file: string, method: string, abstractFqcn: string, abstractNorm: string, concreteFqcn: string, concreteNorm: string}>
     */
    private function extractBindingsFromProviders(): array
    {
        $out = [];
        foreach ($this->iterateProviderFiles() as $path) {
            $stmts = $this->parseStatements($path);
            if ($stmts === null) {
                continue;
            }

            $visitor = new ServiceContainerBindingVisitor($path);
            $tr      = new NodeTraverser();
            $tr->addVisitor(new NameResolver());
            $tr->addVisitor($visitor);
            $tr->traverse($stmts);

            foreach ($visitor->getBindings() as $b) {
                $out[] = $b;
            }
        }

        return $out;
    }

    /**
     * @return array<string, true>
     */
    private function collectAbstractUsageNorms(): array
    {
        $collector = new AbstractBindingUsageCollector();
        foreach (ProjectPhpIterator::iterate($this->scanner, $this->scanPaths, $this->pathExclude) as $path) {
            $stmts = $this->parseStatements($path);
            if ($stmts === null) {
                continue;
            }
            $tr = new NodeTraverser();
            $tr->addVisitor(new NameResolver());
            $tr->addVisitor($collector);
            $tr->traverse($stmts);
        }

        return $collector->getNorms();
    }

    /**
     * @return \Generator<string>
     */
    private function iterateProviderFiles(): \Generator
    {
        foreach ($this->scanPaths as $base) {
            $providers = rtrim((string) $base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Providers';
            if (! is_dir($providers)) {
                continue;
            }
            foreach ($this->scanner->scanDirectoryLazy($providers) as $file) {
                $r = $file->getRealPath();
                if ($r === false || $this->pathExclude->shouldExclude($r)) {
                    continue;
                }
                if (str_contains(strtolower(basename($r)), 'provider')) {
                    yield $r;
                }
            }
        }
    }

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

    private function extractOneClassFqcnFromFile(string $path): ?string
    {
        $stmts = $this->parseStatements($path);
        if ($stmts === null) {
            return null;
        }

        $ns = null;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_ && $stmt->name !== null) {
                $ns = $stmt->name->toString();
                foreach ($stmt->stmts ?? [] as $inner) {
                    if ($inner instanceof Class_ && $inner->name !== null) {
                        $c = $inner->name->name;

                        return $ns !== '' ? $ns . '\\' . $c : $c;
                    }
                }
            } elseif ($stmt instanceof Class_ && $stmt->name !== null) {
                return $stmt->name->name;
            }
        }

        return null;
    }

    private function norm(string $fqcn): string
    {
        return strtolower(ltrim($fqcn, '\\'));
    }
}

final class ServiceContainerBindingVisitor extends NodeVisitorAbstract
{
    private string $file;

    /** @var list<array{file: string, method: string, abstractFqcn: string, abstractNorm: string, concreteFqcn: string, concreteNorm: string}> */
    private array $bindings = [];

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    /**
     * @return list<array{file: string, method: string, abstractFqcn: string, abstractNorm: string, concreteFqcn: string, concreteNorm: string}>
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    public function enterNode(Node $node): ?int
    {
        if (! $node instanceof MethodCall || ! $node->name instanceof Identifier) {
            return null;
        }

        $m = $node->name->name;
        if (! in_array($m, ['bind', 'singleton', 'scoped', 'instance'], true)) {
            return null;
        }

        $a0 = $node->args[0]->value ?? null;
        $a1 = $node->args[1]->value ?? null;
        if (! $a0 instanceof ClassConstFetch || ! $a1 instanceof ClassConstFetch) {
            return null;
        }

        $abstract = $this->fqcnFromClassConst($a0);
        $concrete = $this->fqcnFromClassConst($a1);
        if ($abstract === null || $concrete === null) {
            return null;
        }

        $this->bindings[] = [
            'file'           => $this->file,
            'method'         => $m,
            'abstractFqcn'   => $abstract,
            'abstractNorm'   => strtolower(ltrim($abstract, '\\')),
            'concreteFqcn'   => $concrete,
            'concreteNorm'   => strtolower(ltrim($concrete, '\\')),
        ];

        return null;
    }

    private function fqcnFromClassConst(ClassConstFetch $expr): ?string
    {
        if (! $expr->name instanceof Identifier || strtolower($expr->name->name) !== 'class') {
            return null;
        }
        $c = $expr->class;
        if ($c instanceof FullyQualified) {
            return $c->toString();
        }
        if ($c instanceof Name) {
            return $c->toString();
        }

        return null;
    }
}

/**
 * Tracks abstract/interface type hints and ::class / app() / make() resolution targets.
 */
final class AbstractBindingUsageCollector extends NodeVisitorAbstract
{
    /** @var array<string, true> */
    private array $norms = [];

    /**
     * @return array<string, true>
     */
    public function getNorms(): array
    {
        return $this->norms;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof ClassConstFetch && $node->name instanceof Identifier && strtolower($node->name->name) === 'class') {
            $this->addClassFx($node->class);

            return null;
        }

        if ($node instanceof ClassMethod && strtolower($node->name->name) === '__construct') {
            foreach ($node->params as $param) {
                $this->addParamType($param);
            }

            return null;
        }

        if ($node instanceof FuncCall) {
            $fn = $this->funcName($node);
            if (($fn === 'app' || $fn === 'resolve') && isset($node->args[0])) {
                $v = $node->args[0]->value ?? null;
                if ($v instanceof ClassConstFetch) {
                    $this->addClassFx($v->class);
                }
            }

            return null;
        }

        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            $m = strtolower($node->name->name);
            if (in_array($m, ['make', 'makeWith', 'resolve', 'get'], true) && isset($node->args[0])) {
                $v = $node->args[0]->value ?? null;
                if ($v instanceof ClassConstFetch) {
                    $this->addClassFx($v->class);
                }
            }
        }

        return null;
    }

    private function addParamType(Param $param): void
    {
        $t = $param->type;
        if ($t === null) {
            return;
        }
        $this->addTypeNode($t);
    }

    private function addTypeNode(Node $type): void
    {
        if ($type instanceof NullableType) {
            $this->addTypeNode($type->type);

            return;
        }
        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $t) {
                $this->addTypeNode($t);
            }

            return;
        }
        if ($type instanceof FullyQualified) {
            $this->norms[strtolower($type->toString())] = true;
        } elseif ($type instanceof Name) {
            $this->norms[strtolower($type->toString())] = true;
        }
    }

    private function addClassFx(Node $class): void
    {
        if ($class instanceof FullyQualified) {
            $this->norms[strtolower($class->toString())] = true;
        } elseif ($class instanceof Name) {
            $this->norms[strtolower($class->toString())] = true;
        }
    }

    private function funcName(FuncCall $node): ?string
    {
        $n = $node->name;
        if ($n instanceof Name) {
            return $n->getLast();
        }
        if ($n instanceof FullyQualified) {
            return $n->getLast();
        }

        return null;
    }
}
