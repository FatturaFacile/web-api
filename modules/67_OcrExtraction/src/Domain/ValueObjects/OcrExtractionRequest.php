<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Domain\ValueObjects;

use Modules\OcrExtraction\Domain\Enums\DocumentCategory;

/**
 * What an engine is asked to read.
 */
final readonly class OcrExtractionRequest
{
    /**
     * @param  array<string, mixed>  $settings  decrypted tenant settings of the engine (endpoint, model, API key, ...)
     * @param  list<string>  $skipFieldKeys  fields already known with certainty (a Swiss QR-bill) the engine need not read
     */
    public function __construct(
        public string $contents,
        public string $mimeType,
        public DocumentCategory $category,
        public array $settings = [],
        public array $skipFieldKeys = [],
    ) {}
}
