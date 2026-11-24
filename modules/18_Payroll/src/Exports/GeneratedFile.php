<?php

declare(strict_types=1);

namespace Modules\Payroll\Exports;

/**
 * An exported file and the note that goes with it.
 */
final readonly class GeneratedFile
{
    public function __construct(
        public string $fileName,
        public string $content,
        public string $summary,
    ) {}
}
