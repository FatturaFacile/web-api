<?php

declare(strict_types=1);

namespace Modules\Documents\Engines;

use Modules\Documents\Contracts\OcrEngine;
use Modules\Documents\DTOs\OcrResult;
use Modules\Documents\Exceptions\OcrFailedException;

/**
 * Built-in engine: text formats already hold their text, so reading them needs no recognition. Images and scans need
 * an engine registered by the installation (tag `dms.ocr_engines`).
 */
final class PlainTextOcrEngine implements OcrEngine
{
    private const array TextTypes = ['application/json', 'application/xml', 'text/csv', 'application/csv'];

    public function name(): string
    {
        return 'plain_text';
    }

    public function supports(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'text/') || in_array($mimeType, self::TextTypes, true);
    }

    public function recognize(string $contents, string $mimeType): OcrResult
    {
        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new OcrFailedException('The file is not valid UTF-8 text.');
        }

        if (trim($contents) === '') {
            throw new OcrFailedException('The file holds no text.');
        }

        return new OcrResult($contents, 1, 100.0);
    }
}
