<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Domain\Exceptions;

final class AiQuotaExceededException extends AiCognitiveException
{
    public static function monthlyBudget(string $provider, int $budget): self
    {
        return new self("The monthly token budget of {$budget} for the provider [{$provider}] is used up.");
    }

    public static function rateLimited(): self
    {
        return new self('Too many AI requests in the last minute. Try again shortly.');
    }
}
