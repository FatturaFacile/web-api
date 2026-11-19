<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Commands;

/**
 * Convert a document to PDF/A-1b or PDF/A-2b.
 */
final readonly class ConvertToPdfACommand
{
    public function __construct(
        public string $content,
        public string $mime,
        public string $filename,
        public string $level = 'PDF/A-2b',
    ) {}
}
