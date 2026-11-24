<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a value cannot be encoded as the requested barcode. The machine-readable `reason` identifies the
 * failed rule and `value` is the offending input.
 */
class BarcodeGenerationException extends RuntimeException
{
    public const EMPTY_VALUE = 'empty_value';

    public const INVALID_CHARACTERS = 'invalid_characters';

    public const INVALID_LENGTH = 'invalid_length';

    public const INVALID_CHECKSUM = 'invalid_checksum';

    public const UNSUPPORTED_TYPE = 'unsupported_type';

    public const UNSUPPORTED_FORMAT = 'unsupported_format';

    public const RENDERING_FAILED = 'rendering_failed';

    public function __construct(public readonly string $reason, public readonly ?string $value = null)
    {
        parent::__construct(__("labels.errors.barcode.{$reason}"));
    }

    public static function because(string $reason, ?string $value = null): self
    {
        return new self($reason, $value);
    }
}
