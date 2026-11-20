<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Services;

use Imagick;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Turns a PDF into page images for the engines that read images only. Uses Imagick when loaded, else `pdftoppm`
 * (poppler). Returns an empty list when neither is available, which the adapters report as a provider error.
 */
final class DocumentRasterizer
{
    /**
     * Page images of the document (an image is returned as it is).
     *
     * @return list<array{mime: string, data: string}> raw PNG/JPEG bytes per page, at most $maxPages
     */
    public function pages(string $contents, string $mimeType, int $maxPages = 5): array
    {
        if (str_starts_with($mimeType, 'image/')) {
            return [['mime' => $mimeType, 'data' => $contents]];
        }

        if ($mimeType !== 'application/pdf') {
            return [];
        }

        return $this->withImagick($contents, $maxPages) ?? $this->withPdftoppm($contents, $maxPages);
    }

    /**
     * @return list<array{mime: string, data: string}>|null null when Imagick cannot do it
     */
    private function withImagick(string $pdf, int $maxPages): ?array
    {
        if (! extension_loaded('imagick')) {
            return null;
        }

        try {
            $imagick = new Imagick;
            $imagick->setResolution(150, 150);
            $imagick->readImageBlob($pdf);
            $pages = [];

            foreach ($imagick as $index => $page) {
                if ($index >= $maxPages) {
                    break;
                }

                $page->setImageFormat('png');
                $pages[] = ['mime' => 'image/png', 'data' => $page->getImageBlob()];
            }

            return $pages === [] ? null : $pages;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{mime: string, data: string}>
     */
    private function withPdftoppm(string $pdf, int $maxPages): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ocr_'.bin2hex(random_bytes(8));

        if (! @mkdir($directory, 0700) && ! is_dir($directory)) {
            return [];
        }

        try {
            file_put_contents($directory.DIRECTORY_SEPARATOR.'in.pdf', $pdf);
            $process = new Process(['pdftoppm', '-png', '-r', '150', '-l', (string) $maxPages, $directory.DIRECTORY_SEPARATOR.'in.pdf', $directory.DIRECTORY_SEPARATOR.'page'], null, null, null, 60);
            $process->run();

            if (! $process->isSuccessful()) {
                return [];
            }

            $files = glob($directory.DIRECTORY_SEPARATOR.'page*.png') ?: [];
            sort($files);

            return array_map(static fn (string $file): array => ['mime' => 'image/png', 'data' => (string) file_get_contents($file)], $files);
        } catch (Throwable) {
            return [];
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        }
    }
}
