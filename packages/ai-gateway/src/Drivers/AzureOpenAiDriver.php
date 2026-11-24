<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\Drivers;

use Enterprise\AiGateway\Contracts\AiDriverInterface;
use Enterprise\AiGateway\DTOs\AiPayload;
use Enterprise\AiGateway\DTOs\AiResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class AzureOpenAiDriver implements AiDriverInterface
{
    public function __construct(
        private readonly ?string $endpoint = null,
        private readonly ?string $apiKey = null,
        private readonly ?string $deployment = 'gpt-4o',
        private readonly string $defaultEmbeddingDeployment = 'text-embedding-3-small'
    ) {}

    public function getName(): string
    {
        return 'azure';
    }

    public function executeInference(AiPayload $payload): AiResponse
    {
        $startTime = microtime(true);
        $deployment = $payload->model ?? $this->deployment;

        if (empty($this->endpoint) || empty($this->apiKey)) {
            return new AiResponse(
                success: false,
                content: '',
                driverUsed: $this->getName(),
                modelUsed: $deployment,
                errorMessage: 'Azure OpenAI endpoint or API key not configured'
            );
        }

        try {
            $url = rtrim($this->endpoint, '/')."/openai/deployments/{$deployment}/chat/completions?api-version=2024-02-15-preview";
            $response = Http::withHeaders(['api-key' => $this->apiKey])
                ->timeout(30)
                ->post($url, [
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
                    modelUsed: $deployment,
                    latencyMs: $latency
                );
            }

            return new AiResponse(
                success: false,
                content: '',
                driverUsed: $this->getName(),
                modelUsed: $deployment,
                latencyMs: $latency,
                errorMessage: $response->body()
            );
        } catch (Throwable $e) {
            return new AiResponse(
                success: false,
                content: '',
                driverUsed: $this->getName(),
                modelUsed: $deployment,
                latencyMs: (microtime(true) - $startTime) * 1000,
                errorMessage: $e->getMessage()
            );
        }
    }

    public function generateEmbeddings(string $text, ?string $model = null): array
    {
        if (empty($this->endpoint) || empty($this->apiKey)) {
            throw new RuntimeException('Azure OpenAI endpoint or API key not configured for embeddings.');
        }

        $deployment = $model ?? $this->defaultEmbeddingDeployment;
        $url = rtrim($this->endpoint, '/')."/openai/deployments/{$deployment}/embeddings?api-version=2024-02-15-preview";

        $response = Http::withHeaders(['api-key' => $this->apiKey])
            ->timeout(20)
            ->post($url, ['input' => $text]);

        if (! $response->successful()) {
            throw new RuntimeException("Azure OpenAI embedding request failed: {$response->body()}");
        }

        return $response->json()['data'][0]['embedding'] ?? [];
    }
}
