<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Pdfa;

/**
 * Test double: wraps the original bytes in a PDF that carries PDF/A identification (XMP and output intent) so the
 * pipeline can be exercised without Ghostscript or LibreOffice. The output is NOT a validated PDF/A file.
 */
final class SimulatedTestDriver implements PdfAConverterDriver
{
    public function name(): string
    {
        return 'simulated';
    }

    public function convert(string $content, string $mime, string $filename, string $level): string
    {
        return SimplePdfWriter::build(
            $filename,
            ['Simulated PDF/A conversion (test driver)', 'Original: '.$filename, 'Original SHA-256: '.hash('sha256', $content)],
            ['part' => $level === 'PDF/A-1b' ? 1 : 2, 'conformance' => 'B'],
            base64_encode($content),
        );
    }
}
