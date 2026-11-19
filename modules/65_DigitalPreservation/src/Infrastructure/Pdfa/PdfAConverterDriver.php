<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Pdfa;

use Modules\DigitalPreservation\Domain\Exceptions\PreservationException;

/**
 * Converts a PDF or an image to PDF/A-1b or PDF/A-2b.
 */
interface PdfAConverterDriver
{
    public function name(): string;

    /**
     * @param  string  $level  `PDF/A-1b` or `PDF/A-2b`
     *
     * @throws PreservationException when the conversion fails
     */
    public function convert(string $content, string $mime, string $filename, string $level): string;
}
