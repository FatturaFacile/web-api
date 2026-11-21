<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Adapters;

use Modules\AiCognitive\Domain\Enums\AiProviderType;
use Modules\AiCognitive\Domain\ValueObjects\ProviderSettings;
use Modules\Copilot\Contracts\LlmProviderInterface;
use Modules\Copilot\Llm\AnthropicLlmProvider;

/**
 * Anthropic Claude on the tenant's own key (BYOK). Anthropic offers no embedding model: vectors come from the local hash
 * embedding, so the knowledge text is not sent out for indexing.
 */
final class AnthropicAdapter extends AbstractCopilotBackedAdapter
{
    public function provider(): AiProviderType
    {
        return AiProviderType::Anthropic;
    }

    protected function llm(ProviderSettings $settings): LlmProviderInterface
    {
        return new AnthropicLlmProvider($settings->apiKey, (string) $settings->endpoint, $settings->model);
    }
}
