<?php

declare(strict_types=1);

use Enterprise\AiGateway\Contracts\AiDriverInterface;
use Enterprise\AiGateway\DTOs\AiPayload;
use Enterprise\AiGateway\DTOs\AiRequest;
use Enterprise\AiGateway\DTOs\AiResponse;
use Enterprise\AiGateway\Services\AiDriverRegistry;
use Enterprise\AiGateway\Services\EnterpriseAiGateway;

test('ai gateway manages multi-driver registration, inference and embedding fallbacks', function () {
    $registry = new AiDriverRegistry;

    // Failing primary driver
    $failingDriver = new class implements AiDriverInterface
    {
        public function getName(): string
        {
            return 'primary_failing';
        }

        public function executeInference(AiPayload $payload): AiResponse
        {
            return new AiResponse(
                success: false,
                content: '',
                driverUsed: $this->getName(),
                errorMessage: 'Rate limit exceeded'
            );
        }

        public function generateEmbeddings(string $text, ?string $model = null): array
        {
            throw new RuntimeException('Connection timeout');
        }
    };

    // Resilient fallback driver
    $fallbackDriver = new class implements AiDriverInterface
    {
        public function getName(): string
        {
            return 'resilient_local';
        }

        public function executeInference(AiPayload $payload): AiResponse
        {
            return new AiResponse(
                success: true,
                content: 'Resilient response',
                driverUsed: $this->getName(),
                modelUsed: 'local-v1'
            );
        }

        public function generateEmbeddings(string $text, ?string $model = null): array
        {
            return [0.123, 0.456, 0.789];
        }
    };

    $registry->register('primary_failing', $failingDriver);
    $registry->register('resilient_local', $fallbackDriver);
    $registry->setDefaultDriver('primary_failing');
    $registry->setFallbackDriver('resilient_local');

    $gateway = new EnterpriseAiGateway($registry);

    // 1. Verify inference fallback
    $response = $gateway->predict(new AiRequest(
        taskType: 'chat',
        payload: ['prompt' => 'Hello Enterprise ERP']
    ));

    expect($response->success)->toBeTrue()
        ->and($response->content)->toBe('Resilient response')
        ->and($response->driverUsed)->toBe('resilient_local');

    // 2. Verify embeddings fallback
    $embeddings = $gateway->generateEmbeddings('Semantic search text');
    expect($embeddings)->toBe([0.123, 0.456, 0.789]);
});
