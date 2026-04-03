<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Analyzers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use SplFileInfo;
use Arafa\DeadcodeDetector\Analyzers\Contracts\AnalyzerInterface;
use Arafa\DeadcodeDetector\DTOs\DeadCodeResult;
use Arafa\DeadcodeDetector\Support\AstParserFactory;
use Arafa\DeadcodeDetector\Support\ClassKindClassifier;
use Arafa\DeadcodeDetector\Support\ExtendsImplementsAndTraitsIndex;
use Arafa\DeadcodeDetector\Support\PhpClassAstHelper;
use Arafa\DeadcodeDetector\Support\PhpFileScanner;
use Arafa\DeadcodeDetector\Support\PhpFilesUnderScanPaths;
use Arafa\DeadcodeDetector\Support\PathExcludeMatcher;

class CommandsAnalyzer implements AnalyzerInterface
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
        return function_exists('app_path') ? [app_path('Console/Commands')] : [];
    }

    public function getName(): string
    {
        return 'commands';
    }

    public function getDescription(): string
    {
        return 'Finds Artisan command classes (extends Command) not registered in Console\\Kernel or bootstrap withCommands().';
    }

    public function analyze(): array
    {
        /** @var array<string, array{file: SplFileInfo, fqcn: string}> $map */
        $map = [];
        foreach (PhpFilesUnderScanPaths::eachUniqueRealPath($this->scanner, $this->scanPaths, $this->pathExclude) as $path) {
            $kinds = PhpClassAstHelper::classifyFile($path);
            if ($kinds === null || ! ClassKindClassifier::hasKind($kinds, ClassKindClassifier::KIND_COMMAND)) {
                continue;
            }
            $fqcn = PhpClassAstHelper::fqcnFromFile($path);
            if ($fqcn === null) {
                continue;
            }
            $map[strtolower(ltrim($fqcn, '\\'))] = ['file' => new SplFileInfo($path), 'fqcn' => $fqcn];
        }

        if ($map === []) {
            return [];
        }

        $targets = array_fill_keys(array_keys($map), true);
        $reg     = $this->collectRegisteredCommandNorms($targets);
        $hier    = ExtendsImplementsAndTraitsIndex::build($this->scanner, $this->scanPaths, $this->pathExclude);

        $results = [];
        foreach ($map as $norm => $meta) {
            if (isset($reg[$norm]) || isset($hier[$norm])) {
                continue;
            }
            $p = $meta['file']->getRealPath();
            if ($p === false) {
                continue;
            }
            $results[] = DeadCodeResult::fromArray([
                'analyzerName'   => $this->getName(),
                'type'           => 'command',
                'filePath'       => $p,
                'className'      => $meta['fqcn'],
                'methodName'     => null,
                'lastModified'   => date('Y-m-d H:i:s', $meta['file']->getMTime()),
                'isSafeToDelete' => false,
            ]);
        }

        return $results;
    }

    /**
     * @param array<string, true> $targets
     *
     * @return array<string, true>
     */
    private function collectRegisteredCommandNorms(array $targets): array
    {
        $found = [];
        $v     = new RegisteredArtisanCommandVisitor($targets, $found);

        foreach ($this->scanPaths as $base) {
            $kernel = rtrim((string) $base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Console' . DIRECTORY_SEPARATOR . 'Kernel.php';
            if (is_file($kernel)) {
                $this->traverseWithVisitor($kernel, $v);
            }
        }

        if (function_exists('base_path')) {
            $boot = base_path('bootstrap/app.php');
            if (is_file($boot)) {
                $this->traverseWithVisitor($boot, $v);
            }
        }

        return $found;
    }

    private function traverseWithVisitor(string $path, RegisteredArtisanCommandVisitor $v): void
    {
        $stmts = $this->parse($path);
        if ($stmts === null) {
            return;
        }
        $tr = new NodeTraverser();
        $tr->addVisitor(new NameResolver());
        $tr->addVisitor($v);
        $tr->traverse($stmts);
    }

    private function extractFqcn(string $path): ?string
    {
        $stmts = $this->parse($path);
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

                        return ($ns !== '' && $ns !== null) ? $ns . '\\' . $c : $c;
                    }
                }
            } elseif ($stmt instanceof Class_ && $stmt->name !== null) {
                return $stmt->name->name;
            }
        }

        return null;
    }

    private function parse(string $path): ?array
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

final class RegisteredArtisanCommandVisitor extends NodeVisitorAbstract
{
    /**
     * @param array<string, true> $targets
     * @param array<string, true> $found
     */
    public function __construct(
        private readonly array $targets,
        private array &$found,
    ) {}

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Property && ! $node->isStatic()) {
            foreach ($node->props as $prop) {
                if (strtolower($prop->name->name) !== 'commands') {
                    continue;
                }
                $def = $prop->default;
                if (! $def instanceof Array_) {
                    continue;
                }
                foreach ($def->items as $item) {
                    if ($item === null) {
                        continue;
                    }
                    $v = $item->value ?? null;
                    if ($v instanceof ClassConstFetch) {
                        $this->markFx($v->class);
                    }
                }
            }
        }

        if ($node instanceof MethodCall && $node->name instanceof Identifier && strtolower($node->name->name) === 'withcommands') {
            foreach ($node->args as $arg) {
                $v = $arg->value ?? null;
                if ($v instanceof Array_) {
                    foreach ($v->items as $it) {
                        if ($it === null) {
                            continue;
                        }
                        $x = $it->value ?? null;
                        if ($x instanceof ClassConstFetch) {
                            $this->markFx($x->class);
                        }
                    }
                }
            }
        }

        return null;
    }

    private function markFx(?Node $c): void
    {
        if ($c instanceof FullyQualified) {
            $n = strtolower($c->toString());
        } elseif ($c instanceof Name) {
            $n = strtolower($c->toString());
        } else {
            return;
        }
        if (isset($this->targets[$n])) {
            $this->found[$n] = true;
        }
    }
}
