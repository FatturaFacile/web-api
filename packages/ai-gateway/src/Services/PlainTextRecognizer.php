<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\Services;

use Enterprise\AiGateway\Contracts\TextRecognizerInterface;

/**
 * Reads text and XML files. Scans and PDF files need a real OCR engine behind the same contract: the installation has
 * none, so for those it answers null and the extraction is recorded as failed instead of guessing.
 */
final class PlainTextRecognizer implements TextRecognizerInterface
{
    public function textOf(string $contents, ?string $mimeType): ?string
    {
        $mime = strtolower((string) $mimeType);

        if (! str_starts_with($mime, 'text/') && ! in_array($mime, ['application/xml', 'application/json'], true)) {
            return null;
        }

        return mb_check_encoding($contents, 'UTF-8') ? $contents : mb_convert_encoding($contents, 'UTF-8', 'ISO-8859-1');
    }
}
