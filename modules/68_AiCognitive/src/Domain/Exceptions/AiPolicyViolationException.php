<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Domain\Exceptions;

final class AiPolicyViolationException extends AiCognitiveException
{
    public static function noProvider(): self
    {
        return new self('No AI provider of the tenant may process this data. Enable a provider in the AI settings.');
    }

    public static function suggestionClosed(string $status): self
    {
        return new self("The suggestion is already {$status}.");
    }

    public static function externalNotAllowed(string $provider): self
    {
        return new self("The tenant did not allow external AI providers, so [{$provider}] cannot be used.");
    }
}
