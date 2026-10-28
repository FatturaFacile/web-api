<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\Services;

use Enterprise\AiGateway\Contracts\AiDriverInterface;
use Enterprise\AiGateway\Contracts\EnterpriseAiGatewayInterface;
use Enterprise\AiGateway\DTOs\AiPayload;
use Enterprise\AiGateway\DTOs\AiRequest;
use Enterprise\AiGateway\DTOs\AiResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class EnterpriseAiGateway implements EnterpriseAiGatewayInterface
{
    public function __construct(
        private readonly AiDriverRegistry $registry
    ) {}

    public function predict(AiRequest $request): AiResponse
    {
        $driver = $this->resolveDriver($request->taskType);
        $payload = new AiPayload(
            prompt: $request->payload['prompt'] ?? json_encode($request->payload),
            parameters: $request->payload,
            model: $request->payload['model'] ?? null
        );

        try {
            $response = $driver->executeInference($payload);

            if ($response->success) {
                return $response;
            }

            Log::warning("AI Driver [{$driver->getName()}] reported error, invoking fallback: {$response->errorMessage}");

            return $this->invokeFallback($payload);
        } catch (Throwable $e) {
            Log::error("AI Driver [{$driver->getName()}] crashed, invoking fallback: {$e->getMessage()}");

            return $this->invokeFallback($payload);
        }
    }

    public function classify(AiRequest $request): AiResponse
    {
        return $this->predict($request);
    }

    /**
     * Provider-driven vector embeddings with automated secondary fallback.
     *
     * @return array<int, float>
     */
    public function generateEmbeddings(string $text): array
    {
        $embeddingDriverName = config('ai.embedding_driver', config('ai.default_driver', 'openai'));
        $driver = $this->registry->has($embeddingDriverName)
            ? $this->registry->get($embeddingDriverName)
            : $this->registry->getDefaultDriver();

        try {
            return $driver->generateEmbeddings($text);
        } catch (Throwable $e) {
            Log::warning("AI Embedding Driver [{$driver->getName()}] failed, invoking fallback: {$e->getMessage()}");

            try {
                $fallback = $this->registry->getFallbackDriver();

                return $fallback->generateEmbeddings($text);
            } catch (Throwable $fallbackException) {
                Log::error("All AI Embedding Drivers failed: {$fallbackException->getMessage()}");
                throw new RuntimeException("AI Embedding Generation Failed: {$fallbackException->getMessage()}", 0, $fallbackException);
            }
        }
    }

    private function resolveDriver(string $taskType): AiDriverInterface
    {
        $driverName = config("ai.routing.{$taskType}", config('ai.default_driver', 'ollama'));

        if ($this->registry->has($driverName)) {
            return $this->registry->get($driverName);
        }

        return $this->registry->getDefaultDriver();
    }

    private function invokeFallback(AiPayload $payload): AiResponse
    {
        try {
            $fallback = $this->registry->getFallbackDriver();

            return $fallback->executeInference($payload);
        } catch (Throwable $e) {
            return new AiResponse(
                success: false,
                content: '',
                driverUsed: 'fallback',
                errorMessage: "All AI drivers failed: {$e->getMessage()}"
            );
        }
    }
}
