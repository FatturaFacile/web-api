<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when an uploaded file or payload does not contain a readable Swiss QR-bill.
 */
class QrBillParseException extends RuntimeException
{
    public const UNSUPPORTED_FILE = 'unsupported_file';

    public const QR_NOT_FOUND = 'qr_not_found';

    public const INVALID_PAYLOAD = 'invalid_payload';

    private function __construct(public readonly string $reason)
    {
        parent::__construct(__("qr_bill.parse_errors.{$reason}"));
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
