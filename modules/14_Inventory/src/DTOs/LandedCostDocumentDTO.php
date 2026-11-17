<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Enums\LandedCostAllocationKey;
use Modules\Inventory\Enums\LandedCostType;
use Modules\Inventory\Support\Decimal;

final readonly class LandedCostDocumentDTO
{
    /**
     * @param  list<string>  $receiptIds
     */
    public function __construct(
        public string $documentDate,
        public LandedCostType $costType,
        public LandedCostAllocationKey $allocationKey,
        public string $currency,
        public string $totalAmount,
        public array $receiptIds,
        public ?string $supplierInvoiceId = null,
        public ?string $notes = null,
        public string $exchangeRate = '1',
        public ?string $userId = null,
    ) {}

    /**
     * Total converted to company currency (2 decimals) — the amount that is allocated and journalled.
     */
    public function companyCurrencyTotal(): string
    {
        return Decimal::money(Decimal::mul($this->totalAmount, $this->exchangeRate));
    }
}
