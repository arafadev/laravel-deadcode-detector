<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Creates a PHP parser compatible with nikic/php-parser v4 and v5.
 */
final class AstParserFactory
{
    public static function createParser(): Parser
    {
        $factory = new ParserFactory();

        if (method_exists($factory, 'createForNewestSupportedVersion')) {
            return $factory->createForNewestSupportedVersion();
        }

        return $factory->create(ParserFactory::PREFER_PHP7);
    }
}
