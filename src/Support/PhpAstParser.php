<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use PhpParser\Error;
use PhpParser\Node;

/**
 * Parses PHP files on demand. No disk cache — keeps storage clean and avoids stale AST issues.
 */
final class PhpAstParser
{
    /**
     * @return list<Node>|null
     */
    public static function parseFile(string $absolutePath): ?array
    {
        $real = realpath($absolutePath);
        if ($real === false || ! is_file($real)) {
            return null;
        }

        $code = @file_get_contents($real);
        if ($code === false) {
            return null;
        }

        try {
            $stmts = AstParserFactory::createParser()->parse($code);
        } catch (Error $e) {
            return null;
        }

        return $stmts;
    }
}
