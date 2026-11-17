<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionVersion;
use Modules\SubscriptionBilling\Support\BillingCycle;

/**
 * SaaS metrics dashboard: MRR, ARR, MRR movements, logo/revenue churn, ARPA, LTV and cancellation reasons.
 */
final class SubscriptionMetricsService
{
    /** Statuses that generate recurring revenue. */
    private const REVENUE_STATUSES = ['active', 'past_due', 'cancelling'];

    /**
     * @return array<string, mixed>
     */
    public function summary(string $tenantId, ?Carbon $from = null, ?Carbon $to = null, float $grossMarginPct = 100.0): array
    {
        $from = ($from ?? now()->startOfMonth())->copy()->startOfDay();
        $to = ($to ?? $from->copy()->endOfMonth())->copy()->endOfDay();

        $revenueSubs = Subscription::where('tenant_id', $tenantId)->whereIn('status', self::REVENUE_STATUSES)->get(['id', 'customer_id', 'mrr']);
        $mrr = round((float) $revenueSubs->sum('mrr'), 2);
        $activeCustomers = $revenueSubs->pluck('customer_id')->unique()->count();
        $arpa = $activeCustomers > 0 ? round($mrr / $activeCustomers, 2) : 0.0;

        $movements = $this->mrrMovements($tenantId, $from, $to);
        $mrrStart = round($mrr - $movements['new'] - $movements['expansion'] - $movements['contraction'] + $movements['churned'], 2);

        $customersAtStart = Subscription::where('tenant_id', $tenantId)
            ->where('status', '!=', 'draft')
            ->whereDate('start_date', '<', $from->toDateString())
            ->where(fn ($q) => $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>=', $from))
            ->pluck('customer_id')->unique();

        $churnedSubs = Subscription::where('tenant_id', $tenantId)
            ->whereNotNull('cancelled_at')
            ->whereBetween('cancelled_at', [$from, $to])
            ->get(['id', 'customer_id', 'cancellation_category', 'cancellation_reason']);

        // A customer only counts as churned if they hold no other revenue-generating subscription.
        $retained = $revenueSubs->pluck('customer_id')->unique();
        $churnedCustomers = $churnedSubs->pluck('customer_id')->unique()->reject(fn ($id) => $retained->contains($id));
        $churnedFromStart = $churnedCustomers->filter(fn ($id) => $customersAtStart->contains($id))->count();

        $logoChurnRate = $customersAtStart->count() > 0 ? round($churnedFromStart / $customersAtStart->count() * 100, 2) : 0.0;
        $revenueChurnRate = $mrrStart > 0 ? round($movements['churned'] / $mrrStart * 100, 2) : 0.0;
        $netRevenueRetention = $mrrStart > 0
            ? round(($mrrStart + $movements['expansion'] + $movements['contraction'] - $movements['churned']) / $mrrStart * 100, 2)
            : null;

        $periodDays = max(1, BillingCycle::daysInclusive($from, $to));
        // LTV = ARPA x gross margin / monthly customer churn rate (churn normalised to a 30-day month).
        $monthlyChurn = $logoChurnRate / 100 * (30 / $periodDays);
        $ltv = $monthlyChurn > 0 ? round($arpa * ($grossMarginPct / 100) / $monthlyChurn, 2) : null;

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'mrr' => $mrr,
            'arr' => round($mrr * 12, 2),
            'active_subscriptions' => $revenueSubs->count(),
            'active_customers' => $activeCustomers,
            'arpa' => $arpa,
            'mrr_movements' => $movements + ['net_new' => round($movements['new'] + $movements['expansion'] + $movements['contraction'] - $movements['churned'], 2)],
            'mrr_start_of_period' => $mrrStart,
            'customers_start_of_period' => $customersAtStart->count(),
            'churned_customers' => $churnedFromStart,
            'churned_subscriptions' => $churnedSubs->count(),
            'customer_churn_rate_pct' => $logoChurnRate,
            'revenue_churn_rate_pct' => $revenueChurnRate,
            'net_revenue_retention_pct' => $netRevenueRetention,
            'ltv' => $ltv,
            'churn_reasons' => $this->churnReasons($tenantId, $churnedSubs),
        ];
    }

    /**
     * Month-by-month churn/MRR trend for the last $months months (current month included).
     *
     * @return array<int, array<string, mixed>>
     */
    public function trend(string $tenantId, int $months = 6): array
    {
        $series = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $start = now()->startOfMonth()->subMonthsNoOverflow($i);
            $summary = $this->summary($tenantId, $start, $start->copy()->endOfMonth());
            $series[] = [
                'month' => $start->format('Y-m'),
                'new_mrr' => $summary['mrr_movements']['new'],
                'churned_mrr' => $summary['mrr_movements']['churned'],
                'customer_churn_rate_pct' => $summary['customer_churn_rate_pct'],
                'revenue_churn_rate_pct' => $summary['revenue_churn_rate_pct'],
                'churned_customers' => $summary['churned_customers'],
            ];
        }

        return $series;
    }

    /**
     * @return array{new: float, expansion: float, contraction: float, churned: float}
     */
    private function mrrMovements(string $tenantId, Carbon $from, Carbon $to): array
    {
        $versions = SubscriptionVersion::where('tenant_id', $tenantId)
            ->whereBetween('effective_date', [$from->toDateString(), $to->toDateString()])
            ->get(['change_type', 'mrr_delta']);

        $new = (float) $versions->where('change_type', 'initial')->sum('mrr_delta');
        $churned = -(float) $versions->where('change_type', 'cancellation')->sum('mrr_delta');
        $changes = $versions->whereIn('change_type', ['upgrade', 'downgrade', 'amendment']);

        return [
            'new' => round($new, 2),
            'expansion' => round((float) $changes->where('mrr_delta', '>', 0)->sum('mrr_delta'), 2),
            'contraction' => round((float) $changes->where('mrr_delta', '<', 0)->sum('mrr_delta'), 2),
            'churned' => round($churned, 2),
        ];
    }

    /**
     * Cancellation reasons grouped by category with lost MRR, most impactful first.
     *
     * @param  Collection<int, Subscription>  $churnedSubs
     * @return array<int, array{category: string, count: int, lost_mrr: float, share_pct: float, sample_reasons: array<int, string>}>
     */
    private function churnReasons(string $tenantId, $churnedSubs): array
    {
        if ($churnedSubs->isEmpty()) {
            return [];
        }

        $lostBySub = SubscriptionVersion::where('tenant_id', $tenantId)
            ->where('change_type', 'cancellation')
            ->whereIn('subscription_id', $churnedSubs->pluck('id'))
            ->get(['subscription_id', 'mrr_delta'])
            ->groupBy('subscription_id')
            ->map(fn ($rows) => -(float) $rows->sum('mrr_delta'));

        $total = $churnedSubs->count();

        return $churnedSubs
            ->groupBy(fn (Subscription $s): string => $s->cancellation_category ?: 'unspecified')
            ->map(fn ($group, string $category): array => [
                'category' => $category,
                'count' => $group->count(),
                'lost_mrr' => round((float) $group->sum(fn (Subscription $s): float => (float) ($lostBySub[$s->id] ?? 0)), 2),
                'share_pct' => round($group->count() / $total * 100, 2),
                'sample_reasons' => $group->pluck('cancellation_reason')->filter()->unique()->take(3)->values()->all(),
            ])
            ->sortByDesc('lost_mrr')
            ->values()
            ->all();
    }
}
