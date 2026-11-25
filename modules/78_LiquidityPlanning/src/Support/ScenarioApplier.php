<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Support;

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Applies the levers of a what-if scenario to the flows of the baseline: later customer collections, scaled inflows and
 * outflows, dearer supplies, capex brought forward or rescaled and an extra financing drawdown. The baseline flows are
 * never modified; the result is a new list.
 */
final class ScenarioApplier
{
    /**
     * @param  list<Flow>  $flows
     * @return list<Flow>
     */
    public function apply(array $flows, Levers $levers, CarbonImmutable $start): array
    {
        if ($levers->isNeutral()) {
            return $flows;
        }

        $result = [];

        foreach ($flows as $flow) {
            foreach ($this->transform($flow, $levers, $start) as $changed) {
                $result[] = $changed;
            }
        }

        if (Decimal::isPositive($levers->additionalFinancingAmount)) {
            $date = $levers->additionalFinancingDate !== null ? CarbonImmutable::parse($levers->additionalFinancingDate)->startOfDay()->max($start) : $start;
            $result[] = new Flow($date, Flow::Inflow, Flow::Probable, 'other', 'Additional financing', $levers->additionalFinancingAmount, 'lpApi.category.additional_financing');
        }

        return $result;
    }

    /**
     * @return list<Flow>
     */
    private function transform(Flow $flow, Levers $levers, CarbonImmutable $start): array
    {
        if ($flow->isCustomerInflow()) {
            $amount = $this->scale($flow->amount, $levers->inflowChangePercent);

            if ($levers->collectionDelayDays === 0 || $levers->collectionDelaySharePercent === 0) {
                return [$flow->withAmount($amount)];
            }

            $delayed = Decimal::round2(Decimal::div(Decimal::mul($amount, (string) $levers->collectionDelaySharePercent), '100'));
            $kept = Decimal::round2(Decimal::sub($amount, $delayed));
            $parts = [$flow->withAmount($delayed)->withDate($flow->date->addDays($levers->collectionDelayDays))];

            if (Decimal::isPositive($kept)) {
                $parts[] = $flow->withAmount($kept);
            }

            return $parts;
        }

        if ($flow->isInflow()) {
            return [$flow];
        }

        if ($flow->isDebtService()) {
            return [$flow];
        }

        if ($flow->isCapex()) {
            $date = $flow->date->subDays($levers->capexAccelerationDays)->max($start);

            return [$flow->withAmount($this->scale($flow->amount, $levers->capexChangePercent))->withDate($date)];
        }

        $amount = $this->scale($flow->amount, $levers->outflowChangePercent);

        if ($flow->isSupplierOutflow()) {
            $amount = $this->scale($amount, $levers->rawMaterialIncreasePercent);
        }

        return [$flow->withAmount($amount)];
    }

    private function scale(string $amount, string $percent): string
    {
        if (Decimal::isZero($percent)) {
            return $amount;
        }

        return Decimal::round2(Decimal::mul($amount, Decimal::add('1', Decimal::div($percent, '100'))));
    }
}
