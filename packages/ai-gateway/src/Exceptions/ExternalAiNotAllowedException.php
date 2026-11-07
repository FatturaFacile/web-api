<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\Exceptions;

use RuntimeException;

final class ExternalAiNotAllowedException extends RuntimeException
{
    public static function forDriver(string $driver): self
    {
        return new self("The AI driver [{$driver}] is external and this tenant did not enable external providers.");
    }
}
