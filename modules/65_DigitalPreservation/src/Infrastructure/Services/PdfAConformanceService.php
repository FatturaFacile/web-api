<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Services;

use Modules\DigitalPreservation\Domain\Exceptions\PreservationException;
use Modules\DigitalPreservation\Infrastructure\Pdfa\GhostscriptDriver;
use Modules\DigitalPreservation\Infrastructure\Pdfa\LibreOfficeDriver;
use Modules\DigitalPreservation\Infrastructure\Pdfa\PdfAConverterDriver;
use Modules\DigitalPreservation\Infrastructure\Pdfa\SimulatedTestDriver;

/**
 * Inspects PDFs for PDF/A identification and converts non-archival documents (images, plain PDFs) to PDF/A-1b or PDF/A-2b
 * through the configured driver. Inspection is a structural check (header, `/GTS_PDFA1` output intent, XMP
 * `pdfaid:part`); full validation needs a validator such as veraPDF.
 */
final class PdfAConformanceService
{
    public const string Level1b = 'PDF/A-1b';

    public const string Level2b = 'PDF/A-2b';

    /**
     * Open formats accepted for long-term storage as they are: extension => canonical mime types.
     *
     * @var array<string, list<string>>
     */
    private const array OpenFormats = [
        'xml' => ['application/xml', 'text/xml'],
        'p7m' => ['application/pkcs7-mime', 'application/octet-stream'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'json' => ['application/json'],
    ];

    /**
     * PDF/A level declared by the file, or null when it is not PDF/A.
     */
    public function inspect(string $content): ?string
    {
        if (! str_starts_with($content, '%PDF-') || ! str_contains($content, '/GTS_PDFA1')) {
            return null;
        }

        if (preg_match('/pdfaid:part(?:>|=")\s*([12])/', $content, $part) !== 1 || preg_match('/pdfaid:conformance(?:>|=")\s*[Bb]/', $content) !== 1) {
            return null;
        }

        return $part[1] === '1' ? self::Level1b : self::Level2b;
    }

    /**
     * Whether the file can be kept as it is: an open format or a PDF/A.
     */
    public function isArchival(string $content, string $mime, string $filename): bool
    {
        return $this->openFormat($mime, $filename) !== null || $this->inspect($content) !== null;
    }

    /**
     * Archival form of a file: unchanged when it already is one, otherwise converted to PDF/A.
     *
     * @return array{content: string, mime: string, filename: string, level: ?string, pdfa: bool, converted: bool}
     *
     * @throws PreservationException when the conversion is needed and fails
     */
    public function ensureArchival(string $content, string $mime, string $filename, string $target = self::Level2b): array
    {
        $open = $this->openFormat($mime, $filename);

        if ($open !== null) {
            return ['content' => $content, 'mime' => $mime, 'filename' => $filename, 'level' => $open === 'xml' || $open === 'p7m' ? 'XML' : null, 'pdfa' => false, 'converted' => false];
        }

        $level = $this->inspect($content);

        if ($level !== null) {
            return ['content' => $content, 'mime' => 'application/pdf', 'filename' => $filename, 'level' => $level, 'pdfa' => true, 'converted' => false];
        }

        $converted = $this->driver()->convert($content, $mime, $filename, $target);
        $resultLevel = $this->inspect($converted);

        if ($resultLevel === null) {
            throw new PreservationException('The converted file does not declare PDF/A conformance.');
        }

        return [
            'content' => $converted, 'mime' => 'application/pdf', 'filename' => pathinfo($filename, PATHINFO_FILENAME).'_pdfa.pdf',
            'level' => $resultLevel, 'pdfa' => true, 'converted' => true,
        ];
    }

    public function driver(): PdfAConverterDriver
    {
        return match ((string) config('digital_preservation.pdfa_driver')) {
            'ghostscript' => new GhostscriptDriver,
            'libreoffice' => new LibreOfficeDriver,
            'simulated' => new SimulatedTestDriver,
            default => throw new PreservationException('Unknown PDF/A driver.'),
        };
    }

    private function openFormat(string $mime, string $filename): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        foreach (self::OpenFormats as $format => $mimes) {
            if ($extension === $format && in_array(strtolower($mime), $mimes, true)) {
                return $format;
            }
        }

        return null;
    }
}
