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
 * Local, on-premise models through Ollama (llama3.3, mistral-nemo, qwen2.5-coder, nomic-embed-text): zero egress, which is
 * what Swiss nLPD and EU GDPR data residency ask for.
 */
final class OllamaAdapter extends AbstractCopilotBackedAdapter
{
    public function provider(): AiProviderType
    {
        return AiProviderType::Ollama;
    }

    protected function llm(ProviderSettings $settings): LlmProviderInterface
    {
        return new OpenAiCompatibleLlmProvider('ollama', rtrim((string) $settings->endpoint, '/').'/v1', null, $settings->model, external: false, requiresKey: false);
    }

    protected function embedder(ProviderSettings $settings): EmbeddingProviderInterface
    {
        return new OpenAiCompatibleEmbeddingProvider('ollama', rtrim((string) $settings->endpoint, '/').'/v1', null, $settings->embeddingModel, external: false, requiresKey: false);
    }
}
