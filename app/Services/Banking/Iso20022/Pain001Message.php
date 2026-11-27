<?php

declare(strict_types=1);

namespace App\Services\Banking\Iso20022;

use Carbon\CarbonImmutable;

/**
 * Header, debtor and payments of one pain.001 file with a single payment information block.
 */
final readonly class Pain001Message
{
    /**
     * @param  array<int, Pain001Payment>  $payments
     */
    public function __construct(
        public string $messageId,
        public CarbonImmutable $createdAt,
        public string $initiatorName,
        public string $paymentInfoId,
        public CarbonImmutable $executionDate,
        public string $debtorName,
        public string $debtorIban,
        public ?string $debtorBic,
        public array $payments,
        public ?Pain001CategoryPurpose $categoryPurpose = null,
        public ?Pain001ChargeBearer $chargeBearer = null,
    ) {}

    public function controlSum(): string
    {
        return number_format(array_sum(array_map(fn (Pain001Payment $payment): float => $payment->amount, $this->payments)), 2, '.', '');
    }
}
