<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Sources;

use App\Models\RecurringContracts\RecurringContract;
use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\LeaseAccounting\Models\LeaseScheduleLine;
use Modules\LeaseAccounting\Models\LeaseScheduleVersion;
use Modules\LiquidityPlanning\Support\Flow;
use Modules\Treasury\Models\RecurringCashItem;

/**
 * The highly probable recurring flows: the invoicing of the active recurring contracts (Mod 039), the scheduled lease
 * payments of the current lease schedules and the recurring cash items of the treasury (rent, instalments, fees).
 */
final class ContractsSource implements FlowSource
{
    public function flows(SourceContext $context): array
    {
        return [...$this->recurringContracts($context), ...$this->leases($context), ...$this->treasuryItems($context)];
    }

    /**
     * @return list<Flow>
     */
    private function recurringContracts(SourceContext $context): array
    {
        $flows = [];
        $contracts = RecurringContract::query()
            ->where('tenant_id', $context->tenantId)
            ->where('status', 'active')
            ->where('currency', $context->currency)
            ->whereNotNull('next_billing_date')
            ->with('lines')
            ->get();

        foreach ($contracts as $contract) {
            $amount = Decimal::round2($contract->calculateTotal());

            if (! Decimal::isPositive($amount)) {
                continue;
            }

            $date = CarbonImmutable::parse($contract->next_billing_date)->startOfDay();
            $stop = $contract->end_date === null ? $context->end : CarbonImmutable::parse($contract->end_date)->min($context->end);
            $term = SourceContext::termDays(null);

            for ($guard = 0; $date->lessThanOrEqualTo($stop) && $guard < 400; $guard++) {
                $flows[] = new Flow($context->onOrAfterStart($date->addDays($term)), Flow::Inflow, Flow::Probable, 'recurring_contract', (string) $contract->contract_number, $amount, 'lpApi.category.recurring_contract');
                $date = match ($contract->billing_frequency) {
                    'quarterly' => $date->addMonthsNoOverflow(3),
                    'semi_annual' => $date->addMonthsNoOverflow(6),
                    'annual' => $date->addYearNoOverflow(),
                    'custom_days' => $date->addDays(max(1, (int) $contract->custom_interval_days)),
                    default => $date->addMonthNoOverflow(),
                };
            }
        }

        return array_values(array_filter($flows, static fn (Flow $flow): bool => $flow->date->lessThanOrEqualTo($context->end)));
    }

    /**
     * @return list<Flow>
     */
    private function leases(SourceContext $context): array
    {
        $versions = LeaseScheduleVersion::query()->where('status', 'current')->pluck('id')->all();

        if ($versions === []) {
            return [];
        }

        return LeaseScheduleLine::query()
            ->whereIn('schedule_version_id', $versions)
            ->where('status', 'scheduled')
            ->whereDate('period_start', '<=', $context->end->toDateString())
            ->get()
            ->filter(static fn (LeaseScheduleLine $line): bool => Decimal::isPositive((string) $line->payment))
            ->map(static fn (LeaseScheduleLine $line): Flow => new Flow($context->onOrAfterStart($line->period_start), Flow::Outflow, Flow::Probable, 'lease', 'Lease #'.$line->period_number, Decimal::round2((string) $line->payment), 'lpApi.category.lease'))
            ->values()
            ->all();
    }

    /**
     * @return list<Flow>
     */
    private function treasuryItems(SourceContext $context): array
    {
        $flows = [];

        foreach (RecurringCashItem::query()->where('is_active', true)->where('currency', $context->currency)->get() as $item) {
            foreach ($item->occurrencesBetween($context->start, $context->end) as $date) {
                $flows[] = new Flow($date, $item->direction === 'inflow' ? Flow::Inflow : Flow::Outflow, Flow::Probable, $item->direction === 'inflow' ? 'recurring_contract' : 'lease', (string) $item->name, Decimal::round2((string) $item->amount), $item->direction === 'inflow' ? 'lpApi.category.recurring_contract' : 'lpApi.category.lease');
            }
        }

        return $flows;
    }
}
