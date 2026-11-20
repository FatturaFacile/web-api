<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Pdfa;

use Illuminate\Support\Facades\File;
use Modules\DigitalPreservation\Domain\Exceptions\PreservationException;
use Symfony\Component\Process\Process;

/**
 * PDF to PDF/A through Ghostscript (`-dPDFA`). Only PDF input is supported; convert images with the LibreOffice driver.
 * The result should be checked with a PDF/A validator (veraPDF) before relying on it legally.
 */
final class GhostscriptDriver implements PdfAConverterDriver
{
    public function name(): string
    {
        return 'ghostscript';
    }

    public function convert(string $content, string $mime, string $filename, string $level): string
    {
        if ($mime !== 'application/pdf') {
            throw new PreservationException('The Ghostscript driver converts PDF files only.');
        }

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdfa_'.bin2hex(random_bytes(8));
        File::makeDirectory($directory, 0700, true);

        try {
            $input = $directory.DIRECTORY_SEPARATOR.'in.pdf';
            $output = $directory.DIRECTORY_SEPARATOR.'out.pdf';
            file_put_contents($input, $content);

            $process = new Process([
                (string) config('digital_preservation.ghostscript_binary'), '-dBATCH', '-dNOPAUSE', '-dQUIET', '-dNOOUTERSAVE',
                '-dPDFA='.($level === 'PDF/A-1b' ? '1' : '2'), '-dPDFACompatibilityPolicy=1', '-sColorConversionStrategy=RGB',
                '-sDEVICE=pdfwrite', '-sOutputFile='.$output, $input,
            ]);
            $process->setTimeout(120);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($output)) {
                throw new PreservationException('Ghostscript could not convert the file: '.trim($process->getErrorOutput()));
            }

            return (string) file_get_contents($output);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
