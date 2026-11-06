<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

/**
 * Line of a sales document. When `$tax` is null the tax is computed from the rate, as the desktop does; when it comes
 * from the ledger it is used as posted, so that the settlement clears exactly what the VAT accounts hold.
 */
final readonly class VatSaleItem
{
    public function __construct(
        public string $lineTotal,
        public string $taxRate,
        public ?string $tax = null,
    ) {}
}
