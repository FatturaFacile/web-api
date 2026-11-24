<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\Drivers;

use Enterprise\AiGateway\Contracts\AiDriverInterface;
use Enterprise\AiGateway\DTOs\AiPayload;
use Enterprise\AiGateway\DTOs\AiResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class OllamaDriver implements AiDriverInterface
{
    public function __construct(
        private readonly string $host = 'http://localhost:11434',
        private readonly string $defaultModel = 'llama3.2',
        private readonly string $defaultEmbeddingModel = 'nomic-embed-text'
    ) {}

    public function getName(): string
    {
        return 'ollama';
    }

    public function executeInference(AiPayload $payload): AiResponse
    {
        $startTime = microtime(true);
        $model = $payload->model ?? $this->defaultModel;

        try {
            $response = Http::timeout(60)
                ->post(rtrim($this->host, '/').'/api/generate', [
                    'model' => $model,
                    'prompt' => $payload->prompt,
                    'stream' => false,
                    'options' => [
                        'temperature' => $payload->temperature,
                    ],
                ]);

            $latency = (microtime(true) - $startTime) * 1000;

            if ($response->successful()) {
                $body = $response->json();

                return new AiResponse(
                    success: true,
                    content: $body['response'] ?? '',
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
        $chosenModel = $model ?? $this->defaultEmbeddingModel;
        $response = Http::timeout(30)
            ->post(rtrim($this->host, '/').'/api/embeddings', [
                'model' => $chosenModel,
                'prompt' => $text,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Ollama embedding request failed: {$response->body()}");
        }

        return $response->json()['embedding'] ?? [];
    }
}
