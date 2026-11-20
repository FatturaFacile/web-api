<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Domain\Contracts;

use Modules\OcrExtraction\Domain\Enums\OcrProviderType;
use Modules\OcrExtraction\Domain\Exceptions\OcrProviderException;
use Modules\OcrExtraction\Domain\ValueObjects\OcrExtractionRequest;
use Modules\OcrExtraction\Domain\ValueObjects\OcrExtractionResult;

/**
 * Strategy of an OCR / Vision-LLM engine. Every implementation answers in the schema of ExtractedPayloadDto with
 * BoundingBoxDto regions, so engines are interchangeable per tenant. Engines run outside any database transaction.
 */
interface OcrVisionProviderInterface
{
    public function type(): OcrProviderType;

    /**
     * @throws OcrProviderException when the engine is unreachable, misconfigured or cannot read the file
     */
    public function extract(OcrExtractionRequest $request): OcrExtractionResult;
}
