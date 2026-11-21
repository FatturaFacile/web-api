<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Domain\Exceptions;

final class AiCognitiveNotFoundException extends AiCognitiveException
{
    public static function suggestion(string $id): self
    {
        return new self("AI suggestion [{$id}] was not found.");
    }
}
