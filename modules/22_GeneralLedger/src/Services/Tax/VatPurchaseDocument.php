<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

/**
 * Posted supplier document registered in the period.
 */
final readonly class VatPurchaseDocument
{
    public function __construct(
        public string $deductibleVat,
        public string $totalNet = '0',
        public bool $reverseCharge = false,
        public ?string $estvBox = null,
        public string $acquisitionTaxAmount = '0',
    ) {}
}
