<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects (controller FQCN, action method) pairs from route definitions.
 *
 * When routes use a short class name ("CartController@index"), every controller class
 * with that basename is marked (avoids false positives when multiple namespaces define
 * the same short name).
 */
final class ControllerRouteActionExtractor extends NodeVisitorAbstract
{
    /** @var array<string, true> key: strtolower(fqcn)::method */
    private array $used = [];

    /**
     * @param array<string, list<string>> $shortBasenameToFqcns lowercased class basename => FQCNs
     */
    public function __construct(
        private readonly array $shortBasenameToFqcns,
    ) {}

    /**
     * @return array<string, true>
     */
    public function getUsedActions(): array
    {
        return $this->used;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof StaticCall && $node->name instanceof Identifier) {
            $method = $node->name->name;

            if (in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match'], true)) {
                if (isset($node->args[1])) {
                    $action = $node->args[1]->value ?? null;
                    if ($action instanceof ClassConstFetch) {
                        foreach ($this->normalizedFqcnKeysFromClassExpr($action->class) as $key) {
                            $this->used[$key . '::__invoke'] = true;
                        }
                    }
                }
            }

            if (in_array($method, ['resource', 'apiResource'], true)) {
                $this->collectResourceActions($node, $method === 'apiResource');

                return null;
            }
        }

        if ($node instanceof String_) {
            $this->collectFromString($node->value);

            return null;
        }

        if ($node instanceof Array_) {
            $this->collectFromArrayTuple($node);

            return null;
        }

        return null;
    }

    private function collectResourceActions(StaticCall $node, bool $apiOnly): void
    {
        if (! isset($node->args[1])) {
            return;
        }

        $secondArg = $node->args[1]->value ?? null;
        if (! $secondArg instanceof ClassConstFetch) {
            return;
        }

        $keys = $this->normalizedFqcnKeysFromClassExpr($secondArg->class);

        if ($keys === []) {
            return;
        }

        $actions = $apiOnly
            ? ['index', 'store', 'show', 'update', 'destroy']
            : ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'];

        foreach ($keys as $key) {
            foreach ($actions as $a) {
                $this->used[$key . '::' . strtolower($a)] = true;
            }
        }
    }

    private function collectFromString(string $value): void
    {
        if (! str_contains($value, '@')) {
            return;
        }

        [$classPart, $action] = explode('@', $value, 2);
        $classPart = trim($classPart);
        $action    = trim($action);
        if ($classPart === '' || $action === '') {
            return;
        }

        foreach ($this->normalizedFqcnKeysFromRouteClassPart($classPart) as $key) {
            $this->used[$key . '::' . strtolower($action)] = true;
        }
    }

    private function collectFromArrayTuple(Array_ $node): void
    {
        if (count($node->items) < 2) {
            return;
        }

        $first  = $node->items[0]->value ?? null;
        $second = $node->items[1]->value ?? null;

        if (! $first instanceof ClassConstFetch) {
            return;
        }

        $keys = $this->normalizedFqcnKeysFromClassExpr($first->class);
        if ($keys === []) {
            return;
        }

        if ($second instanceof String_) {
            $m = trim($second->value);
            if ($m !== '') {
                foreach ($keys as $key) {
                    $this->used[$key . '::' . strtolower($m)] = true;
                }
            }
        }
    }

    /**
     * Route string may be "App\Http\Foo\BarController" or short "BarController".
     *
     * @return list<string> normalized FQCN keys (lowercase, no leading \)
     */
    private function normalizedFqcnKeysFromRouteClassPart(string $classPart): array
    {
        $classPart = ltrim($classPart, '\\');
        if (str_contains($classPart, '\\')) {
            return [strtolower($classPart)];
        }

        return $this->normalizedFqcnKeysForShortBasename($classPart);
    }

    /**
     * @return list<string>
     */
    private function normalizedFqcnKeysForShortBasename(string $basename): array
    {
        $keys = [];
        foreach ($this->shortBasenameToFqcns[strtolower($basename)] ?? [] as $fqcn) {
            $keys[] = strtolower(ltrim($fqcn, '\\'));
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function normalizedFqcnKeysFromClassExpr(?Name $expr): array
    {
        if ($expr === null) {
            return [];
        }

        if ($expr instanceof FullyQualified) {
            return [strtolower(ltrim($expr->toString(), '\\'))];
        }

        if ($expr instanceof Name) {
            $ref = $expr->toString();
            if (str_contains($ref, '\\')) {
                return [strtolower(ltrim($ref, '\\'))];
            }

            return $this->normalizedFqcnKeysForShortBasename($ref);
        }

        return [];
    }
}
