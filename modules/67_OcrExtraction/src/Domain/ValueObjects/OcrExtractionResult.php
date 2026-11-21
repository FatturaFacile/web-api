<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Domain\ValueObjects;

use Modules\OcrExtraction\Domain\Enums\OcrProviderType;

/**
 * What an engine read: the normalized payload, the regions the fields come from, and the tokens it spent.
 */
final readonly class OcrExtractionResult
{
    /**
     * @param  array<string, BoundingBoxDto>  $boundingBoxes  keyed by field key (`header.document_number`, `lines[0].unit_price`)
     */
    public function __construct(
        public ExtractedPayloadDto $payload,
        public array $boundingBoxes,
        public OcrProviderType $provider,
        public int $tokensUsed = 0,
    ) {}
}
