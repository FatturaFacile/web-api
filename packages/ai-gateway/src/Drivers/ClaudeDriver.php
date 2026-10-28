<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\Drivers;

use Enterprise\AiGateway\Contracts\AiDriverInterface;
use Enterprise\AiGateway\DTOs\AiPayload;
use Enterprise\AiGateway\DTOs\AiResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class ClaudeDriver implements AiDriverInterface
{
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly string $defaultModel = 'claude-3-5-sonnet-20241022'
    ) {}

    public function getName(): string
    {
        return 'claude';
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
                errorMessage: 'Claude API key not configured'
            );
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ])
                ->timeout(30)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model,
                    'max_tokens' => 4096,
                    'messages' => [
                        ['role' => 'user', 'content' => $payload->prompt],
                    ],
                ]);

            $latency = (microtime(true) - $startTime) * 1000;

            if ($response->successful()) {
                $body = $response->json();
                $content = $body['content'][0]['text'] ?? '';

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
        throw new RuntimeException('Anthropic Claude does not provide native vector embedding models; route to secondary provider (OpenAI/Ollama).');
    }
}
