<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

/**
 * Result of a VAT return: all amounts are decimal strings with two decimals. `$payable` is positive when the amount is
 * due and negative when it is a credit.
 */
final readonly class VatReturnResult
{
    /**
     * @param  list<VatRateTotal>  $rates  ordered from the highest rate
     * @param  array<string, mixed>  $estvBoxes
     */
    public function __construct(
        public string $country,
        public string $periodFrom,
        public string $periodTo,
        public string $turnover,
        public string $taxableTurnover,
        public string $outputVat,
        public string $inputVat,
        public string $previousCredit,
        public string $previousDebit,
        public string $interest,
        public string $payable,
        public bool $belowMinimumPayment,
        public array $rates,
        public string $details,
        public string $regime = 'effective',
        public string $grossTurnover = '0.00',
        public string $exemptTurnover = '0.00',
        public string $acquisitionTaxBase = '0.00',
        public string $acquisitionTaxAmount = '0.00',
        public string $grossInputVatForfeited = '0.00',
        public string $flatRateAdjustmentAmount = '0.00',
        public array $estvBoxes = [],
    ) {}
}
