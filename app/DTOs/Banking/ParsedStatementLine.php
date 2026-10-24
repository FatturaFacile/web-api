<?php

declare(strict_types=1);

namespace App\DTOs\Banking;

use Carbon\CarbonImmutable;

/**
 * One accounting line of a bank statement, normalised across CBI and ISO 20022 sources. The amount is the net amount
 * booked on the account and always positive; the direction tells whether it came in or went out. Fees withheld by the
 * bank are kept apart in `feeAmount`, so the gross amount the debtor paid is `amount + feeAmount`.
 */
final readonly class ParsedStatementLine
{
    public const CREDIT = 'credit';

    public const DEBIT = 'debit';

    public function __construct(
        public CarbonImmutable $bookingDate,
        public CarbonImmutable $valueDate,
        public float $amount,
        public float $feeAmount,
        public string $direction,
        public ?string $abiCode = null,
        public ?string $reference = null,
        public ?string $qrReference = null,
        public ?string $description = null,
        public ?string $counterpartName = null,
        public ?string $counterpartIban = null,
    ) {}

    public function isCredit(): bool
    {
        return $this->direction === self::CREDIT;
    }
}
