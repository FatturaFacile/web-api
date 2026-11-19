<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Pdfa;

use Illuminate\Support\Facades\File;
use Modules\DigitalPreservation\Domain\Exceptions\PreservationException;
use Symfony\Component\Process\Process;

/**
 * Office documents, PDF and images to PDF/A through headless LibreOffice (`SelectPdfVersion`: 1 = PDF/A-1b, 2 = PDF/A-2b).
 * The result should be checked with a PDF/A validator (veraPDF) before relying on it legally.
 */
final class LibreOfficeDriver implements PdfAConverterDriver
{
    public function name(): string
    {
        return 'libreoffice';
    }

    public function convert(string $content, string $mime, string $filename, string $level): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdfa_'.bin2hex(random_bytes(8));
        File::makeDirectory($directory, 0700, true);

        try {
            $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin';
            $input = $directory.DIRECTORY_SEPARATOR.'input.'.preg_replace('/[^A-Za-z0-9]/', '', $extension);
            file_put_contents($input, $content);
            $version = $level === 'PDF/A-1b' ? 1 : 2;

            $process = new Process([
                (string) config('digital_preservation.libreoffice_binary'), '--headless', '--norestore',
                '-env:UserInstallation=file://'.str_replace('\\', '/', $directory).'/profile',
                '--convert-to', 'pdf:writer_pdf_Export:{"SelectPdfVersion":{"type":"long","value":"'.$version.'"}}',
                '--outdir', $directory, $input,
            ]);
            $process->setTimeout(180);
            $process->run();
            $output = $directory.DIRECTORY_SEPARATOR.'input.pdf';

            if (! $process->isSuccessful() || ! is_file($output)) {
                throw new PreservationException('LibreOffice could not convert the file: '.trim($process->getErrorOutput()));
            }

            return (string) file_get_contents($output);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
