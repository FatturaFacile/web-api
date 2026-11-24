<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\DTOs;

final readonly class AiRequest
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $taskType,
        public array $payload,
        public ?string $tenantId = null,
        public ?string $resourceType = null,
        public ?string $resourceId = null
    ) {}
}
