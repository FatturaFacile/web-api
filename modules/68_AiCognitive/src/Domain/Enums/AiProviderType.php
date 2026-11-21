<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Domain\Enums;

/**
 * The model providers a tenant can choose. Ollama runs inside the installation (zero egress); the others are hosted and
 * run on the tenant's own API key (bring your own key).
 */
enum AiProviderType: string
{
    case Ollama = 'ollama';
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';
    case AzureOpenAi = 'azure_openai';

    public function isLocal(): bool
    {
        return $this === self::Ollama;
    }

    public function requiresKey(): bool
    {
        return ! $this->isLocal();
    }
}
