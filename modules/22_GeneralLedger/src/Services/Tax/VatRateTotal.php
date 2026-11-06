<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

/**
 * Taxable amount and tax of one VAT rate.
 */
final readonly class VatRateTotal
{
    public function __construct(
        public string $rate,
        public string $taxable,
        public string $tax,
    ) {}
}
