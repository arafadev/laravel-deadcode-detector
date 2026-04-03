<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Support;

/**
 * Optional hints for {@see ClassKindClassifier} (e.g. DI-registered concretes).
 */
final class ClassKindContext
{
    /**
     * @param array<string, true> $boundConcreteNorms normalized FQCNs that appear as
     *                              concrete implementations in container bind/singleton calls
     */
    public function __construct(
        public readonly array $boundConcreteNorms = [],
    ) {}
}
