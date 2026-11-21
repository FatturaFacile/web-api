<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Adapters;

use Modules\AiCognitive\Domain\Enums\AiProviderType;
use Modules\AiCognitive\Domain\ValueObjects\ProviderSettings;
use Modules\Copilot\Contracts\EmbeddingProviderInterface;
use Modules\Copilot\Contracts\LlmProviderInterface;
use Modules\Copilot\Embeddings\GeminiEmbeddingProvider;
use Modules\Copilot\Llm\GeminiLlmProvider;

/**
 * Google Gemini on the tenant's own key (BYOK).
 */
final class GeminiAdapter extends AbstractCopilotBackedAdapter
{
    public function provider(): AiProviderType
    {
        return AiProviderType::Gemini;
    }

    protected function llm(ProviderSettings $settings): LlmProviderInterface
    {
        return new GeminiLlmProvider($settings->apiKey, (string) $settings->endpoint, $settings->model);
    }

    protected function embedder(ProviderSettings $settings): EmbeddingProviderInterface
    {
        return new GeminiEmbeddingProvider($settings->apiKey, (string) $settings->endpoint, $settings->embeddingModel);
    }
}
