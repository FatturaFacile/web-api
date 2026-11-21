<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Domain\Exceptions;

use DomainException;

/** Raised when a fiscal document or monetary operation violates a domain rule. */
final class SwissFiscalException extends DomainException
{
    public static function currencyMismatch(string $left, string $right): self
    {
        return new self(sprintf('Currency mismatch: %s and %s cannot be combined.', $left, $right));
    }

    public static function invalidAmount(string $value): self
    {
        return new self(sprintf('"%s" is not a valid decimal amount.', $value));
    }

    public static function invalidQrBill(string $reason): self
    {
        return new self('Invalid QR-bill: '.$reason);
    }
}
