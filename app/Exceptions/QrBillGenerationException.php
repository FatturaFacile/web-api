<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when the data of an invoice cannot produce a valid Swiss QR-bill. The machine-readable `reason`
 * identifies the failed rule; `violations` carries the SIX specification violations when there are any.
 */
class QrBillGenerationException extends RuntimeException
{
    public const MISSING_TENANT = 'missing_tenant';

    public const INVALID_CURRENCY = 'invalid_currency';

    public const INVALID_AMOUNT = 'invalid_amount';

    public const MISSING_CREDITOR_IBAN = 'missing_creditor_iban';

    public const INVALID_CREDITOR_IBAN = 'invalid_creditor_iban';

    public const MISSING_CREDITOR_ADDRESS = 'missing_creditor_address';

    public const INVALID_REFERENCE = 'invalid_reference';

    public const REFERENCE_MISMATCH = 'reference_mismatch';

    public const VALIDATION_FAILED = 'validation_failed';

    /**
     * @param  array<int, string>  $violations
     */
    public function __construct(public readonly string $reason, public readonly array $violations = [])
    {
        parent::__construct(__("qr_bill.errors.{$reason}"));
    }

    /**
     * @param  array<int, string>  $violations
     */
    public static function because(string $reason, array $violations = []): self
    {
        return new self($reason, $violations);
    }
}
