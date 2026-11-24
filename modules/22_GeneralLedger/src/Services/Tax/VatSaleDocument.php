<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

/**
 * Issued customer document of the period.
 */
final readonly class VatSaleDocument
{
    /**
     * @param  list<VatSaleItem>  $items
     */
    public function __construct(
        public string $totalNet,
        public array $items,
        public string $invoiceType = 'invoice',
        public bool $splitPayment = false,
        public bool $reverseCharge = false,
    ) {}
}
