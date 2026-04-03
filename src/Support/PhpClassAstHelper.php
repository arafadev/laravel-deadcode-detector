<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;

final class PhpClassAstHelper
{
    /**
     * @param list<\PhpParser\Node>|null $stmts
     *
     * @return array{class: Class_, namespace: ?string}|null
     */
    public static function firstClassDeclaration(?array $stmts): ?array
    {
        if ($stmts === null) {
            return null;
        }

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_ && $stmt->name !== null) {
                $ns = $stmt->name->toString();
                foreach ($stmt->stmts ?? [] as $inner) {
                    if ($inner instanceof Class_ && $inner->name !== null) {
                        return ['class' => $inner, 'namespace' => $ns];
                    }
                }
            } elseif ($stmt instanceof Class_ && $stmt->name !== null) {
                return ['class' => $stmt, 'namespace' => null];
            }
        }

        return null;
    }

    public static function fqcnFromDeclaration(Class_ $class, ?string $namespace): string
    {
        $n = $class->name !== null ? $class->name->name : '';

        return ($namespace !== null && $namespace !== '') ? $namespace . '\\' . $n : $n;
    }

    /**
     * @return list<string>|null Kinds; null if file has no suitable class.
     */
    public static function classifyFile(string $absolutePath, ?ClassKindContext $ctx = null): ?array
    {
        $stmts = CachedAstParser::parseFile($absolutePath);
        $decl  = self::firstClassDeclaration($stmts);
        if ($decl === null) {
            return null;
        }

        return ClassKindClassifier::classify($decl['class'], $decl['namespace'], $absolutePath, $ctx);
    }

    public static function fqcnFromFile(string $absolutePath): ?string
    {
        $stmts = CachedAstParser::parseFile($absolutePath);
        $decl  = self::firstClassDeclaration($stmts);
        if ($decl === null) {
            return null;
        }

        return self::fqcnFromDeclaration($decl['class'], $decl['namespace']);
    }
}
