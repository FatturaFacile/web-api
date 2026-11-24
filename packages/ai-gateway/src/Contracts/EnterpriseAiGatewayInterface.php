<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\Contracts;

use Enterprise\AiGateway\DTOs\AiRequest;
use Enterprise\AiGateway\DTOs\AiResponse;

interface EnterpriseAiGatewayInterface
{
    public function predict(AiRequest $request): AiResponse;

    public function classify(AiRequest $request): AiResponse;

    public function generateEmbeddings(string $text): array;
}
