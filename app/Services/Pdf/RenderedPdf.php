<?php

declare(strict_types=1);

namespace App\Services\Pdf;

/**
 * A generated PDF together with the file name it should be delivered under.
 */
final readonly class RenderedPdf
{
    public function __construct(
        public string $content,
        public string $filename,
    ) {}
}
