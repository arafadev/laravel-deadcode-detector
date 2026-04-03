<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;

/** Collects (controller FQCN, action method) pairs from route definitions. */
final class ControllerRouteActionExtractor extends NodeVisitorAbstract
{
    /** @var array<string, true> key: strtolower(fqcn)::method */
    private array $used = [];

    /**
     * @param array<string, string> $shortBasenameToFqcn Lowercased short class name => FQCN
     */
    public function __construct(
        private readonly array $shortBasenameToFqcn,
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
                        $fqcn = $this->classExprToFqcn($action->class);
                        if ($fqcn !== null) {
                            $this->used[strtolower(ltrim($fqcn, '\\')) . '::__invoke'] = true;
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

        $fqcn = $this->classExprToFqcn($node->args[1]->value ?? null);
        if ($fqcn === null) {
            return;
        }

        $actions = $apiOnly
            ? ['index', 'store', 'show', 'update', 'destroy']
            : ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'];

        $key = strtolower(ltrim($fqcn, '\\'));
        foreach ($actions as $a) {
            $this->used[$key . '::' . $a] = true;
        }
    }

    private function collectFromString(string $value): void
    {
        if (! str_contains($value, '@')) {
            return;
        }

        [$classPart, $method] = explode('@', $value, 2);
        $classPart = trim($classPart);
        $method    = trim($method);
        if ($classPart === '' || $method === '') {
            return;
        }

        $fqcn = $this->resolveClassString($classPart);
        if ($fqcn === null) {
            return;
        }

        $this->used[strtolower(ltrim($fqcn, '\\')) . '::' . strtolower($method)] = true;
    }

    private function collectFromArrayTuple(Array_ $node): void
    {
        if (count($node->items) < 2) {
            return;
        }

        $first  = $node->items[0]->value ?? null;
        $second = $node->items[1]->value ?? null;

        if ($first instanceof ClassConstFetch) {
            $fqcn = $this->classExprToFqcn($first->class);
            if ($fqcn === null) {
                return;
            }

            if ($second instanceof String_) {
                $m = $second->value;
                if ($m !== '') {
                    $this->used[strtolower(ltrim($fqcn, '\\')) . '::' . strtolower($m)] = true;
                }
            }

            return;
        }

        // Invokable: [Something::class] only — handled elsewhere via ClassConstFetch as sole action
    }

    private function resolveClassString(string $classPart): ?string
    {
        if (str_contains($classPart, '\\')) {
            return ltrim($classPart, '\\');
        }

        $key = strtolower($classPart);

        return $this->shortBasenameToFqcn[$key] ?? null;
    }

    private function classExprToFqcn(?Node $expr): ?string
    {
        if ($expr instanceof FullyQualified) {
            return $expr->toString();
        }

        if ($expr instanceof Name) {
            return $expr->toString();
        }

        return null;
    }
}
