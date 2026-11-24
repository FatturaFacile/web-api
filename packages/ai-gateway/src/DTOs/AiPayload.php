<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\DTOs;

final readonly class AiPayload
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public string $prompt,
        public array $parameters = [],
        public ?string $model = null,
        public float $temperature = 0.2
    ) {}
}
