<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\Drivers;

use Enterprise\AiGateway\Contracts\AiDriverInterface;
use Enterprise\AiGateway\DTOs\AiPayload;
use Enterprise\AiGateway\DTOs\AiResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class GeminiDriver implements AiDriverInterface
{
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly string $defaultModel = 'gemini-1.5-pro',
        private readonly string $defaultEmbeddingModel = 'text-embedding-004'
    ) {}

    public function getName(): string
    {
        return 'gemini';
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
                errorMessage: 'Gemini API key not configured'
            );
        }

        try {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$this->apiKey}";
            $response = Http::timeout(30)
                ->post($url, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $payload->prompt],
                            ],
                        ],
                    ],
                ]);

            $latency = (microtime(true) - $startTime) * 1000;

            if ($response->successful()) {
                $body = $response->json();
                $content = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';

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
            throw new RuntimeException('Gemini API key not configured for embeddings.');
        }

        $chosenModel = $model ?? $this->defaultEmbeddingModel;
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$chosenModel}:embedContent?key={$this->apiKey}";

        $response = Http::timeout(20)->post($url, [
            'content' => [
                'parts' => [
                    ['text' => $text],
                ],
            ],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("Gemini embedding request failed: {$response->body()}");
        }

        return $response->json()['embedding']['values'] ?? [];
    }
}
