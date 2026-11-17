<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Enums\ValuationMethod;

/**
 * One computed allocation of a landed cost onto a cost layer (receipt / item / lot).
 */
final readonly class LandedCostAllocationLine
{
    public function __construct(
        public string $receiptId,
        public string $costLayerId,
        public string $itemId,
        public ?string $lotId,
        public ValuationMethod $method,
        public string $basisValue,
        public string $allocatedAmount,
        public string $toInventoryAmount,
        public string $toCogsAmount,
        public string $toVarianceAmount,
    ) {}

    /**
     * @return array{receipt_id: string, cost_layer_id: string, item_id: string, lot_id: string|null, valuation_method: string, basis_value: string, allocated_amount: string, to_inventory_amount: string, to_cogs_amount: string, to_variance_amount: string}
     */
    public function toArray(): array
    {
        return [
            'receipt_id' => $this->receiptId,
            'cost_layer_id' => $this->costLayerId,
            'item_id' => $this->itemId,
            'lot_id' => $this->lotId,
            'valuation_method' => $this->method->value,
            'basis_value' => $this->basisValue,
            'allocated_amount' => $this->allocatedAmount,
            'to_inventory_amount' => $this->toInventoryAmount,
            'to_cogs_amount' => $this->toCogsAmount,
            'to_variance_amount' => $this->toVarianceAmount,
        ];
    }
}
