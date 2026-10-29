<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\Contracts;

use Enterprise\AiGateway\DTOs\AiPayload;
use Enterprise\AiGateway\DTOs\AiResponse;

interface AiDriverInterface
{
    public function executeInference(AiPayload $payload): AiResponse;

    /**
     * @return array<int, float>
     */
    public function generateEmbeddings(string $text, ?string $model = null): array;

    public function getName(): string;
}
