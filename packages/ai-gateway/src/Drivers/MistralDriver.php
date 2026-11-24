<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\Drivers;

use Enterprise\AiGateway\Contracts\AiDriverInterface;
use Enterprise\AiGateway\DTOs\AiPayload;
use Enterprise\AiGateway\DTOs\AiResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class MistralDriver implements AiDriverInterface
{
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly string $defaultModel = 'mistral-small-latest',
        private readonly string $defaultEmbeddingModel = 'mistral-embed'
    ) {}

    public function getName(): string
    {
        return 'mistral';
    }

    public function executeInference(AiPayload $payload): AiResponse
    {
        $startTime = microtime(true);
        $model = $payload->model ?? $this->defaultModel;

        if (empty($this->apiKey)) {
            return new AiResponse(
                success: false,
                content: '',
                driverUsed: $this->getName(),
                modelUsed: $model,
                errorMessage: 'Mistral API key not configured'
            );
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->post('https://api.mistral.ai/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => $payload->prompt],
                    ],
                    'temperature' => $payload->temperature,
                ]);

            $latency = (microtime(true) - $startTime) * 1000;

            if ($response->successful()) {
                $body = $response->json();
                $content = $body['choices'][0]['message']['content'] ?? '';

                return new AiResponse(
                    success: true,
                    content: $content,
                    data: $body,
                    driverUsed: $this->getName(),
                    modelUsed: $model,
                    latencyMs: $latency
                );
            }

            return new AiResponse(
                success: false,
                content: '',
                driverUsed: $this->getName(),
                modelUsed: $model,
                latencyMs: $latency,
                errorMessage: $response->body()
            );
        } catch (Throwable $e) {
            return new AiResponse(
                success: false,
                content: '',
                driverUsed: $this->getName(),
                modelUsed: $model,
                latencyMs: (microtime(true) - $startTime) * 1000,
                errorMessage: $e->getMessage()
            );
        }
    }

    public function generateEmbeddings(string $text, ?string $model = null): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('Mistral API key not configured for embeddings.');
        }

        $chosenModel = $model ?? $this->defaultEmbeddingModel;
        $response = Http::withToken($this->apiKey)
            ->timeout(20)
            ->post('https://api.mistral.ai/v1/embeddings', [
                'model' => $chosenModel,
                'input' => [$text],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Mistral embedding request failed: {$response->body()}");
        }

        return $response->json()['data'][0]['embedding'] ?? [];
    }
}
