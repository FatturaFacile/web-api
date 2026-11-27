<?php

declare(strict_types=1);

namespace Modules\DownPayments\Support;

/**
 * What a chain is built from, read once from its source document: the customer, the currency and the net of the supply per
 * VAT rate. The order is the one the advance invoices bill: the source itself, or the order a quote was converted into.
 */
final readonly class ChainSource
{
    /**
     * @param  array<string, string>  $bases  net per VAT rate (percentage => net)
     * @param  list<array{description: string, vat_rate: string, net: string}>|null  $supplyLines  the lines of a contract source
     */
    public function __construct(
        public string $type,
        public string $id,
        public ?string $number,
        public string $customerId,
        public ?string $customerName,
        public string $currency,
        public string $jurisdiction,
        public ?string $salesOrderId,
        public array $bases,
        public ?array $supplyLines = null,
    ) {}
}
