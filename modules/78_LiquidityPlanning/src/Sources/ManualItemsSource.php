<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Sources;

use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\LiquidityPlanning\Models\ForecastManualItem;
use Modules\LiquidityPlanning\Support\Flow;

/**
 * The manual forecast items of the CFO (assumptions and flows no ledger holds yet), with the tier and category they carry.
 */
final class ManualItemsSource implements FlowSource
{
    public function flows(SourceContext $context): array
    {
        $flows = [];

        foreach (ForecastManualItem::query()->where('is_active', true)->where('currency', $context->currency)->get() as $item) {
            foreach ($item->occurrencesBetween($context->start, $context->end) as $date) {
                $flows[] = new Flow($date, $item->direction === 'inflow' ? Flow::Inflow : Flow::Outflow, $item->tier, $item->category, $item->label, Decimal::round2((string) $item->amount), null);
            }
        }

        return $flows;
    }
}
