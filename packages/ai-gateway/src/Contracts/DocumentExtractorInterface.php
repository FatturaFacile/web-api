<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\Contracts;

use Enterprise\AiGateway\DTOs\ExtractionRequest;
use Enterprise\AiGateway\DTOs\ExtractionResult;
use Enterprise\AiGateway\Exceptions\ExternalAiNotAllowedException;

/**
 * Proposes the fields of a document. The result is a proposal for a person to confirm: nothing here posts or stores.
 */
interface DocumentExtractorInterface
{
    /**
     * @throws ExternalAiNotAllowedException when the configured driver is external and the tenant did not enable it
     */
    public function extract(ExtractionRequest $request): ExtractionResult;
}
