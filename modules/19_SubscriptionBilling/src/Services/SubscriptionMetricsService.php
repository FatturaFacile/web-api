<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use Carbon\CarbonImmutable;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionVersion;
use Modules\SubscriptionBilling\Support\Money;

/**
 * SaaS metrics of a company from its subscriptions and their versions (no estimates):
 *
 * - MRR: monthly recurring revenue of the active and past-due subscriptions (trials, pauses and closed ones excluded);
 *   ARR = MRR x 12;
 * - MRR movements of the window from the versions: new, expansion, contraction, churn, reactivation;
 * - customer churn rate = customers lost in the window / customers at its start; revenue churn rate = churned and
 *   contraction MRR / MRR at its start;
 * - ARPA = MRR / paying customers; LTV = ARPA / monthly customer churn rate (null without churn).
 */
final readonly class SubscriptionMetricsService
{
    /** Statuses that count in the MRR. */
    public const array COUNTED_STATUSES = ['active', 'past_due'];

    /**
     * @return array<string, mixed>
     */
    public function metrics(string $tenantId, CarbonImmutable $asOf, int $windowDays = 30): array
    {
        $windowStart = $asOf->subDays(max(1, $windowDays));
        $live = Subscription::query()->where('tenant_id', $tenantId)->whereIn('status', self::COUNTED_STATUSES);
        $mrr = Money::round2((string) (clone $live)->sum('mrr'));
        $customers = (int) (clone $live)->distinct()->count('customer_id');

        $movements = $this->movements($tenantId, $windowStart, $asOf);
        $netMovement = Money::sum($movements);
        $startingMrr = Money::round2(Money::sub($mrr, $netMovement));

        $lostCustomers = $this->lostCustomers($tenantId, $windowStart, $asOf);
        $startingCustomers = $customers + $lostCustomers - $this->newCustomers($tenantId, $windowStart, $asOf);
        $customerChurn = $startingCustomers > 0 ? Money::div((string) $lostCustomers, (string) $startingCustomers) : '0';
        $monthlyChurn = Money::mul($customerChurn, Money::div('30', (string) max(1, $windowDays)));
        $revenueChurn = Money::isPositive($startingMrr) ? Money::div(Money::abs(Money::add($movements['churn'], $movements['contraction'])), $startingMrr) : '0';
        $arpa = $customers > 0 ? Money::div($mrr, (string) $customers) : '0';

        return [
            'as_of' => $asOf->toDateString(),
            'window_start' => $windowStart->toDateString(),
            'window_days' => $windowDays,
            'currency_note' => 'Amounts are summed in the currency of each subscription.',
            'mrr' => $mrr,
            'arr' => Money::round2(Money::mul($mrr, '12')),
            'active_subscriptions' => (clone $live)->count(),
            'trialing_subscriptions' => Subscription::query()->where('tenant_id', $tenantId)->where('status', 'trialing')->count(),
            'paying_customers' => $customers,
            'starting_mrr' => $startingMrr,
            'mrr_movements' => array_map(fn (string $amount): string => Money::round2($amount), $movements),
            'net_new_mrr' => Money::round2($netMovement),
            'customers_lost' => $lostCustomers,
            'customer_churn_rate' => Money::round($customerChurn, 4),
            'revenue_churn_rate' => Money::round($revenueChurn, 4),
            'arpa' => Money::round2($arpa),
            'ltv' => Money::isPositive($monthlyChurn) ? Money::round2(Money::div($arpa, $monthlyChurn)) : null,
        ];
    }

    /**
     * MRR movements of the window by kind.
     *
     * @return array{new: string, expansion: string, contraction: string, churn: string, reactivation: string}
     */
    private function movements(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $movements = ['new' => '0', 'expansion' => '0', 'contraction' => '0', 'churn' => '0', 'reactivation' => '0'];
        $versions = SubscriptionVersion::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('effective_date', '>', $from->toDateString())
            ->whereDate('effective_date', '<=', $to->toDateString())
            ->get(['change_type', 'mrr_before', 'mrr_after', 'mrr_delta']);

        foreach ($versions as $version) {
            $delta = (string) $version->getRawOriginal('mrr_delta');

            if (Money::isZero($delta)) {
                continue;
            }

            $kind = match (true) {
                in_array($version->change_type, ['initial', 'trial_conversion', 'activation'], true) && Money::isZero((string) $version->getRawOriginal('mrr_before')) => 'new',
                in_array($version->change_type, ['cancellation', 'expiration', 'pause', 'dunning_suspension'], true) => 'churn',
                in_array($version->change_type, ['resume', 'dunning_resolved'], true) => 'reactivation',
                Money::isPositive($delta) => 'expansion',
                default => 'contraction',
            };

            $movements[$kind] = Money::add($movements[$kind], $delta);
        }

        return $movements;
    }

    private function lostCustomers(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return Subscription::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', Subscription::CLOSED_STATUSES)
            ->whereDate('cancellation_effective_date', '>', $from->toDateString())
            ->whereDate('cancellation_effective_date', '<=', $to->toDateString())
            ->whereNotIn('customer_id', Subscription::query()->where('tenant_id', $tenantId)->whereIn('status', self::COUNTED_STATUSES)->select('customer_id'))
            ->distinct()
            ->count('customer_id');
    }

    private function newCustomers(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $earlier = Subscription::query()->where('tenant_id', $tenantId)->whereDate('start_date', '<=', $from->toDateString())->select('customer_id');

        return Subscription::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', self::COUNTED_STATUSES)
            ->whereDate('start_date', '>', $from->toDateString())
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereNotIn('customer_id', $earlier)
            ->distinct()
            ->count('customer_id');
    }
}
