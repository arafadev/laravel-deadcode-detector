<?php

declare(strict_types=1);

namespace Arafa\DeadcodeDetector\Reporters;

use Arafa\DeadcodeDetector\Reporters\Contracts\ReporterInterface;
use Arafa\DeadcodeDetector\Support\ReportPayloadBuilder;
use Illuminate\Console\OutputStyle;

class JsonReporter implements ReporterInterface
{
    public function __construct(
        private readonly OutputStyle $output,
        private readonly ?int $phpFilesInScope = null,
    ) {}

    /**
     * @param \Arafa\DeadcodeDetector\DTOs\DeadCodeResult[] $results
     */
    public function report(array $results): void
    {
        $this->output->writeln(ReportPayloadBuilder::toJson($results, $this->phpFilesInScope));
    }
}
