<?php

declare(strict_types=1);

namespace Modules\Procurement\DTOs;

use Modules\Procurement\Support\Decimal;

/**
 * The allowance that applies to an invoice line: the price may drift by `pricePercent` of the line amount or by
 * `priceAmount` (whichever is larger), the quantity by `quantityPercent` of the billable quantity or by
 * `quantityAmount` units (whichever is larger).
 */
final readonly class MatchTolerance
{
    public function __construct(
        public string $pricePercent,
        public string $priceAmount,
        public string $quantityPercent,
        public string $quantityAmount,
        public ?string $ruleId = null,
        public string $source = 'settings',
    ) {}

    /**
     * Largest amount an invoice line may exceed the order value by.
     */
    public function allowedAmount(string $orderedLineAmount): string
    {
        return Decimal::max(
            Decimal::mul(Decimal::abs($orderedLineAmount), bcdiv($this->pricePercent, '100', 8), 2),
            Decimal::of($this->priceAmount, 2),
            2,
        );
    }

    /**
     * Largest quantity an invoice line may exceed the billable quantity by.
     */
    public function allowedQuantity(string $billableQuantity): string
    {
        return Decimal::max(
            Decimal::mul(Decimal::abs($billableQuantity), bcdiv($this->quantityPercent, '100', 8), 4),
            Decimal::of($this->quantityAmount, 4),
            4,
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'rule_id' => $this->ruleId,
            'price_tolerance_percent' => $this->pricePercent,
            'price_tolerance_amount' => $this->priceAmount,
            'quantity_tolerance_percent' => $this->quantityPercent,
            'quantity_tolerance_amount' => $this->quantityAmount,
        ];
    }
}
