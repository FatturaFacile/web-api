<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\DTOs;

final readonly class ExtractionRequest
{
    public function __construct(
        public string $tenantId,
        public string $text,
        public ?string $documentType = null,
    ) {}
}
