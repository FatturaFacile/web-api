<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\DTOs;

final readonly class AiResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public bool $success,
        public string $content,
        public array $data = [],
        public string $driverUsed = 'mock',
        public ?string $modelUsed = null,
        public float $latencyMs = 0.0,
        public ?string $errorMessage = null
    ) {}
}
