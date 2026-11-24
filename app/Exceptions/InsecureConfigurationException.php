<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The application configuration allows something that is not HTTPS-only (plain-HTTP URL, cookie without the Secure flag,
 * non-HTTPS CORS origin...). Raised at boot, so the application does not start.
 */
final class InsecureConfigurationException extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public static function because(array $problems): self
    {
        return new self(
            "The system works over HTTPS only, in every environment, and the configuration does not:\n - ".implode("\n - ", $problems)
        );
    }
}
