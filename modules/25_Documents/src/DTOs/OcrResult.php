<?php

declare(strict_types=1);

namespace Modules\Documents\DTOs;

/**
 * What an OCR engine read from a file.
 */
final readonly class OcrResult
{
    public function __construct(
        public string $text,
        public int $pageCount = 1,
        public ?float $confidence = null,
    ) {}
}
