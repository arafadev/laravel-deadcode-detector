<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects view/page dot names from view(), View::make, Route::view, Inertia::render, inertia(), and dynamic prefix hints.
 */
final class ViewReferenceCollector extends NodeVisitorAbstract
{
    /** @var array<string, true> */
    private array $dots = [];

    /** @var array<string, true> */
    private array $dynamicPrefixes = [];

    /**
     * @return array<string, true>
     */
    public function getDotNames(): array
    {
        return $this->dots;
    }

    /**
     * @return list<string>
     */
    public function getDynamicViewPrefixes(): array
    {
        return array_keys($this->dynamicPrefixes);
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof FuncCall) {
            $fn = $this->funcName($node);
            if ($fn === 'view' && isset($node->args[0])) {
                $this->consumeViewNameArg($node->args[0]->value ?? null);
            }
            if ($fn === 'inertia' && isset($node->args[0])) {
                $this->consumeInertiaPageArg($node->args[0]->value ?? null);
            }

            return null;
        }

        if ($node instanceof MethodCall) {
            $m = $this->identifierToString($node->name);
            if ($m === 'make' && isset($node->args[0])) {
                $this->consumeViewNameArg($node->args[0]->value ?? null);
            }

            if (strtolower($m) === 'view' && isset($node->args[1])) {
                $this->consumeViewNameArg($node->args[1]->value ?? null);
            }

            return null;
        }

        if ($node instanceof StaticCall) {
            $m = $this->identifierToString($node->name);
            if (strtolower($m) === 'make' && isset($node->args[0])) {
                $this->consumeViewNameArg($node->args[0]->value ?? null);
            }

            if (strtolower($m) === 'view' && isset($node->args[1])) {
                $this->consumeViewNameArg($node->args[1]->value ?? null);
            }

            if (strtolower($m) === 'render' && $this->isInertiaClass($node->class) && isset($node->args[0])) {
                $this->consumeInertiaPageArg($node->args[0]->value ?? null);
            }

            return null;
        }

        return null;
    }

    private function consumeViewNameArg(?Expr $expr): void
    {
        if ($expr instanceof String_) {
            $this->addDot($expr->value);

            return;
        }

        $hint = $this->extractDynamicViewPrefix($expr);
        if ($hint !== null) {
            $this->dynamicPrefixes[$hint] = true;
        }
    }

    private function consumeInertiaPageArg(?Expr $expr): void
    {
        if ($expr instanceof String_) {
            $this->addInertiaDot($expr->value);

            return;
        }

        $hint = $this->extractDynamicViewPrefix($expr);
        if ($hint !== null) {
            $this->dynamicPrefixes[$hint] = true;
        }
    }

    private function addInertiaDot(string $name): void
    {
        $name = str_replace('/', '.', str_replace('\\', '/', trim($name)));
        $name = strtolower($name);
        if ($name !== '') {
            $this->dots[$name] = true;
        }
    }

    private function extractDynamicViewPrefix(?Expr $expr): ?string
    {
        if ($expr === null) {
            return null;
        }

        if ($expr instanceof Concat) {
            $left = $expr->left;
            while ($left instanceof Concat) {
                $left = $left->left;
            }
            if ($left instanceof String_) {
                return $this->leadingSegmentFromLiteral($left->value);
            }

            return null;
        }

        if ($expr instanceof InterpolatedString) {
            $buf = '';
            foreach ($expr->parts as $part) {
                if ($part instanceof InterpolatedStringPart) {
                    $buf .= $part->value;
                } else {
                    break;
                }
            }

            return $this->leadingSegmentFromLiteral($buf);
        }

        return null;
    }

    private function leadingSegmentFromLiteral(string $literal): ?string
    {
        $s = trim(str_replace(['/', '\\'], '.', $literal));
        $s = rtrim($s, '.');
        if ($s === '') {
            return null;
        }
        $parts = explode('.', $s);
        $first = $parts[0] ?? '';
        if ($first === '') {
            return null;
        }

        return strtolower($first);
    }

    private function isInertiaClass(?Node $class): bool
    {
        if ($class instanceof Name) {
            return $class->getLast() === 'Inertia';
        }
        if ($class instanceof FullyQualified) {
            return str_ends_with($class->toString(), '\\Inertia')
                || $class->toString() === 'Inertia';
        }

        return false;
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

        if ($n instanceof FullyQualified) {
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
