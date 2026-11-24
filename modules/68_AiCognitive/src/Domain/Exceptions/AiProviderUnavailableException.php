<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Domain\Exceptions;

final class AiProviderUnavailableException extends AiCognitiveException
{
    public static function circuitOpen(string $provider): self
    {
        return new self("The provider [{$provider}] is temporarily switched off after repeated failures.");
    }

    public static function failed(string $provider, string $reason): self
    {
        return new self("The provider [{$provider}] could not answer: {$reason}");
    }
}
