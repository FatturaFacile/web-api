<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised by the e-commerce drivers and sync actions. The machine-readable `reason` identifies the failure and
 * `context` carries the values interpolated in the translated message.
 */
class EcommerceException extends RuntimeException
{
    public const UNSUPPORTED_DRIVER = 'unsupported_driver';

    public const MISSING_CONFIGURATION = 'missing_configuration';

    public const HTTP_ERROR = 'http_error';

    public const INVALID_RESPONSE = 'invalid_response';

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(public readonly string $reason, public readonly array $context = [])
    {
        parent::__construct(__("ecommerce.errors.{$reason}", $context));
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function because(string $reason, array $context = []): self
    {
        return new self($reason, $context);
    }
}
