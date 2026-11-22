<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Services;

use Modules\Manufacturing\Support\Money;
use Modules\MrpPlanning\Domain\Enums\LotSizingRule;
use Modules\MrpPlanning\Domain\ValueObjects\ItemPlanningData;

/**
 * Turns a net requirement into an order quantity: the item's scrap percentage is added first (so the good output still
 * covers the requirement), then the lot-sizing rule is applied (so MOQ and multiples hold on the quantity that is
 * actually ordered or started).
 */
final class LotSizingCalculator
{
    /**
     * @param  string  $net  the requirement to cover
     * @param  string  $windowRequirement  the shortfall at the end of the fixed-days window (FixedDaysOfSupply only)
     */
    public function orderQuantity(ItemPlanningData $item, string $net, string $windowRequirement = '0'): string
    {
        $required = $this->withScrap($net, $item->scrapPct);

        $quantity = match ($item->lotRule) {
            LotSizingRule::LotForLot => $required,
            LotSizingRule::MinimumOrderQuantity => Money::max($required, $item->moq),
            LotSizingRule::OrderMultiple => Money::ceilToMultiple(Money::max($required, $item->moq), $item->multiple),
            LotSizingRule::EconomicOrderQuantity => Money::ceilToMultiple(Money::max(Money::max($required, $item->eoq), $item->moq), $item->multiple),
            LotSizingRule::FixedDaysOfSupply => Money::ceilToMultiple(Money::max(Money::max($required, $this->withScrap($windowRequirement, $item->scrapPct)), $item->moq), $item->multiple),
        };

        return Money::qty($quantity);
    }

    /**
     * Good output of an order quantity after scrap.
     */
    public function goodOutput(string $orderQuantity, string $scrapPct): string
    {
        return Money::qty(Money::div($orderQuantity, Money::add('1', Money::div($scrapPct, '100'))));
    }

    /**
     * A quantity increased by a scrap percentage, rounded up to the 4 decimals of the stock ledger.
     */
    public function withScrap(string $quantity, string $scrapPct): string
    {
        if (! Money::isPositive($scrapPct)) {
            return Money::qty($quantity);
        }

        $scaled = Money::mul(Money::mul($quantity, Money::add('1', Money::div($scrapPct, '100'))), '10000');

        return Money::qty(Money::div(Money::ceilToMultiple($scaled, '1'), '10000'));
    }
}
