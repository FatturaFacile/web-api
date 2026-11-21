<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Adapters;

use Modules\AiCognitive\Domain\Contracts\AiAdapterInterface;
use Modules\AiCognitive\Domain\Exceptions\AiProviderUnavailableException;
use Modules\AiCognitive\Domain\ValueObjects\AiCompletionResult;
use Modules\AiCognitive\Domain\ValueObjects\ProviderSettings;
use Modules\Copilot\Contracts\EmbeddingProviderInterface;
use Modules\Copilot\Contracts\LlmProviderInterface;
use Modules\Copilot\DTOs\LlmMessage;
use Modules\Copilot\DTOs\LlmRequest;
use Modules\Copilot\Embeddings\LocalHashEmbeddingProvider;
use Throwable;

/**
 * Adapters share the wire clients of the Copilot module (one implementation of each protocol); the settings of the tenant
 * decide endpoint, model and key for every call, so nothing about a tenant lives in the adapter.
 */
abstract class AbstractCopilotBackedAdapter implements AiAdapterInterface
{
    abstract protected function llm(ProviderSettings $settings): LlmProviderInterface;

    /**
     * The embedding client of the provider; null when the provider has none (the local hash embedding is used then, which
     * sends nothing outside the installation).
     */
    protected function embedder(ProviderSettings $settings): ?EmbeddingProviderInterface
    {
        return null;
    }

    public function complete(ProviderSettings $settings, string $system, string $prompt, int $maxTokens, float $temperature, bool $expectJson, bool $light): AiCompletionResult
    {
        $model = $settings->modelFor($light);
        $started = hrtime(true);

        if ($expectJson) {
            $system .= "\n\nAnswer with one JSON object and nothing else.";
        }

        try {
            $response = $this->llm($settings)->complete(new LlmRequest(
                [new LlmMessage('system', $system), new LlmMessage('user', $prompt)],
                [],
                $model,
                $temperature,
                $maxTokens,
            ));
        } catch (Throwable $exception) {
            throw AiProviderUnavailableException::failed($this->provider()->value, $exception->getMessage());
        }

        return new AiCompletionResult(
            $response->content,
            $this->provider()->value,
            $response->usage->model !== '' ? $response->usage->model : (string) $model,
            $response->usage->promptTokens,
            $response->usage->completionTokens,
            (int) round((hrtime(true) - $started) / 1_000_000),
        );
    }

    public function embed(ProviderSettings $settings, string $text): array
    {
        try {
            return ($this->embedder($settings) ?? new LocalHashEmbeddingProvider)->embed($text);
        } catch (Throwable $exception) {
            throw AiProviderUnavailableException::failed($this->provider()->value, $exception->getMessage());
        }
    }
}
