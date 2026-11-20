<?php

declare(strict_types=1);

namespace Modules\Copilot\Llm;

use RuntimeException;

/**
 * A model or embedding provider could not answer (missing key, HTTP error, unreadable reply).
 */
final class CopilotProviderException extends RuntimeException
{
    public static function notConfigured(string $provider): self
    {
        return new self("The Copilot provider [{$provider}] is not configured.");
    }

    public static function failed(string $provider, int $status): self
    {
        return new self("The Copilot provider [{$provider}] answered with HTTP {$status}.");
    }

    public static function unreadable(string $provider): self
    {
        return new self("The Copilot provider [{$provider}] returned a reply that cannot be read.");
    }
}
