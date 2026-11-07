<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\DTOs;

final readonly class ExtractionResult
{
    /**
     * @param  list<array{name: string, value: string, confidence: float}>  $fields
     */
    public function __construct(
        public array $fields,
        public string $driver,
    ) {}
}
