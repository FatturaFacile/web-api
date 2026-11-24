<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Adapters;

use Modules\AiCognitive\Domain\Enums\AiProviderType;
use Modules\AiCognitive\Domain\ValueObjects\ProviderSettings;
use Modules\Copilot\Contracts\EmbeddingProviderInterface;
use Modules\Copilot\Contracts\LlmProviderInterface;
use Modules\Copilot\Embeddings\OpenAiCompatibleEmbeddingProvider;
use Modules\Copilot\Llm\OpenAiCompatibleLlmProvider;

/**
 * OpenAI on the tenant's own key (BYOK).
 */
final class OpenAiAdapter extends AbstractCopilotBackedAdapter
{
    public function provider(): AiProviderType
    {
        return AiProviderType::OpenAi;
    }

    protected function llm(ProviderSettings $settings): LlmProviderInterface
    {
        return new OpenAiCompatibleLlmProvider('openai', (string) $settings->endpoint, $settings->apiKey, $settings->model, external: true);
    }

    protected function embedder(ProviderSettings $settings): EmbeddingProviderInterface
    {
        return new OpenAiCompatibleEmbeddingProvider('openai', (string) $settings->endpoint, $settings->apiKey, $settings->embeddingModel, external: true);
    }
}
