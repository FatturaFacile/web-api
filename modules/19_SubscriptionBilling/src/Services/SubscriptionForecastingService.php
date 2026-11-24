<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\SubscriptionBilling\Contracts\SubscriptionForecastingServiceInterface;
use Modules\SubscriptionBilling\Events\ARRForecastEvent;
use Modules\SubscriptionBilling\Events\MRRForecastEvent;
use Modules\SubscriptionBilling\Events\RecurringRevenueForecastEvent;
use Modules\SubscriptionBilling\Events\SubscriptionCashForecastEvent;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\UsageAggregation;
use Modules\SubscriptionBilling\Support\BillingCalendar;
use Modules\SubscriptionBilling\Support\Money;

/**
 * Forecasts of the contracted recurring revenue for Treasury: the net amounts the live subscriptions will invoice in
 * the coming months at their current prices, until their term ends without renewal or their cancellation takes
 * effect. No growth or churn rate is assumed; the movements reported are those of the last 30 days.
 */
final readonly class SubscriptionForecastingService implements SubscriptionForecastingServiceInterface
{
    public function __construct(
        private SubscriptionHistory $history,
        private PricingCalculator $pricing,
        private SubscriptionMetricsService $metrics,
    ) {}

    /**
     * MRR of the counted subscriptions, as a decimal string.
     */
    public function calculateMRR(string $tenantId, ?string $companyId = null): string
    {
        return Money::round2((string) $this->counted($tenantId, $companyId)->sum('mrr'));
    }

    /**
     * ARR of the counted subscriptions, as a decimal string.
     */
    public function calculateARR(string $tenantId, ?string $companyId = null): string
    {
        return Money::round2((string) $this->counted($tenantId, $companyId)->sum('arr'));
    }

    /**
     * Net amounts to invoice per month over the next `$months` months, and the forecast events for Treasury.
     *
     * @return array{current_mrr: string, current_arr: string, forecast_months: int, projected_cash_inflow: string, monthly_breakdown: array<string, string>}
     */
    public function generateCashForecast(string $tenantId, int $months = 12, ?string $companyId = null): array
    {
        $today = CarbonImmutable::today();
        $horizon = $today->startOfMonth()->addMonthsNoOverflow(max(1, $months));
        $breakdown = [];

        for ($i = 0; $i < max(1, $months); $i++) {
            $breakdown[$today->startOfMonth()->addMonthsNoOverflow($i)->format('Y-m')] = '0';
        }

        $subscriptions = Subscription::query()
            ->with(['plan.pricingTiers', 'items'])
            ->where('tenant_id', $tenantId)
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->whereIn('status', Subscription::BILLABLE_STATUSES)
            ->get();

        foreach ($subscriptions as $subscription) {
            foreach ($this->projectedInvoices($subscription, $today, $horizon) as $month => $amount) {
                if (isset($breakdown[$month])) {
                    $breakdown[$month] = Money::add($breakdown[$month], $amount);
                }
            }
        }

        $breakdown = array_map(fn (string $amount): string => Money::round2($amount), $breakdown);
        $total = Money::round2(Money::sum($breakdown));
        $mrr = $this->calculateMRR($tenantId, $companyId);
        $arr = $this->calculateARR($tenantId, $companyId);
        $metrics = $this->metrics->metrics($tenantId, $today);
        $movements = $metrics['mrr_movements'];
        $company = $companyId ?? $tenantId;

        $this->history->publish(new SubscriptionCashForecastEvent(
            forecastPeriod: array_key_first($breakdown).' to '.array_key_last($breakdown),
            projectedCashInflow: (float) $total,
            currency: 'EUR',
            tenantId: $tenantId,
            companyId: $company,
            breakdown: array_map(fn (string $amount): float => (float) $amount, $breakdown)
        ));

        $this->history->publish(new MRRForecastEvent(
            targetMonth: $today->format('Y-m'),
            startingMrr: (float) $metrics['starting_mrr'],
            newMrr: (float) $movements['new'],
            expansionMrr: (float) $movements['expansion'],
            contractionMrr: (float) Money::abs($movements['contraction']),
            churnMrr: (float) Money::abs($movements['churn']),
            endingMrr: (float) $mrr,
            currency: 'EUR',
            tenantId: $tenantId,
            companyId: $company
        ));

        $startingMrr = (string) $metrics['starting_mrr'];
        $retained = Money::add($startingMrr, Money::add($movements['expansion'], Money::add($movements['contraction'], $movements['churn'])));

        $this->history->publish(new ARRForecastEvent(
            targetYear: $today->format('Y'),
            startingArr: (float) $arr,
            projectedArr: (float) $arr,
            netRetentionRate: (float) (Money::isPositive($startingMrr) ? Money::round(Money::div($retained, $startingMrr), 4) : '1'),
            currency: 'EUR',
            tenantId: $tenantId,
            companyId: $company
        ));

        $nextQuarter = Money::sum(array_slice($breakdown, 0, 3));
        $usageLastQuarter = Money::round2((string) UsageAggregation::query()->where('tenant_id', $tenantId)->where('is_billed', true)->where('billed_at', '>=', $today->subMonths(3))->sum('billable_amount'));

        $this->history->publish(new RecurringRevenueForecastEvent(
            fiscalQuarter: 'Q'.$today->quarter.'-'.$today->year,
            contractedRecurringRevenue: (float) Money::round2($nextQuarter),
            estimatedUsageRevenue: (float) $usageLastQuarter,
            netExpansionRevenue: (float) Money::round2(Money::mul(Money::add($movements['expansion'], $movements['contraction']), '3')),
            currency: 'EUR',
            tenantId: $tenantId,
            companyId: $company
        ));

        return [
            'current_mrr' => $mrr,
            'current_arr' => $arr,
            'forecast_months' => $months,
            'projected_cash_inflow' => $total,
            'monthly_breakdown' => $breakdown,
        ];
    }

    /**
     * Net amount of each future invoice of a subscription, keyed by month, at its current prices.
     *
     * @return array<string, string>
     */
    private function projectedInvoices(Subscription $subscription, CarbonImmutable $today, CarbonImmutable $horizon): array
    {
        $plan = $subscription->plan;

        if ($plan === null || $subscription->current_period_start === null) {
            return [];
        }

        $interval = (string) $plan->billing_interval;
        $count = (int) $plan->interval_count;
        $amount = Money::sum($subscription->items->map(fn ($item): string => $this->pricing->itemAmount($item, $plan)));
        $start = $subscription->current_period_start->max($today);
        $stop = $subscription->cancellation_effective_date;
        $termEnd = $subscription->auto_renew ? null : $subscription->term_end_date;
        $result = [];

        for ($i = 0; $i < 240 && $start->lt($horizon); $i++) {
            if (($stop !== null && $start->gte($stop)) || ($termEnd !== null && $start->gt($termEnd))) {
                break;
            }

            $month = $start->format('Y-m');
            $result[$month] = Money::add($result[$month] ?? '0', $amount);
            $start = BillingCalendar::nextPeriodStart($subscription->anchor(), $start, $interval, $count, (string) $subscription->billing_alignment);
        }

        return $result;
    }

    /**
     * @return Builder<Subscription>
     */
    private function counted(string $tenantId, ?string $companyId): Builder
    {
        return Subscription::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', SubscriptionMetricsService::COUNTED_STATUSES)
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId));
    }
}
