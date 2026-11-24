<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use Modules\SubscriptionBilling\Models\SubscriptionPlan;

/**
 * The plans that carry the subscriptions of the former /api/v1/subscriptions flow: one per tenant, interval, interval
 * count and currency, with no price of their own (the charges are the items of each subscription).
 */
final class LegacyPlanCatalog
{
    /** Interval names of the legacy API mapped to the plan intervals. */
    public const array INTERVALS = ['monthly' => 'monthly', 'quarterly' => 'quarterly', 'semiannual' => 'semiannual', 'yearly' => 'annual'];

    /**
     * The carrier plan of a legacy interval, created on first use.
     */
    public function plan(string $tenantId, string $legacyInterval, int $intervalCount, string $currency): SubscriptionPlan
    {
        $interval = self::INTERVALS[$legacyInterval] ?? 'monthly';
        $count = max(1, $intervalCount);
        $currency = strtoupper($currency);
        $code = mb_substr('LEGACY-'.strtoupper($interval).'-'.$count.'-'.$currency, 0, 50);

        return SubscriptionPlan::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'plan_code' => $code],
            [
                'name' => 'Recurring billing ('.$legacyInterval.($count > 1 ? ' x'.$count : '').', '.$currency.')',
                'description' => 'Carrier plan of the subscriptions of the recurring billing API: the charges are the items of each subscription.',
                'billing_interval' => $interval,
                'interval_count' => $count,
                'pricing_model' => 'flat_fee',
                'currency' => $currency,
                'setup_fee' => '0.00',
                'base_price' => '0.0000',
                'trial_period_days' => 0,
                'min_commitment_cycles' => 1,
                'billing_alignment' => 'anniversary',
                'is_active' => true,
                'metadata' => ['legacy_carrier' => true],
            ]
        );
    }

    /**
     * Legacy interval name of a plan interval.
     */
    public function legacyInterval(?string $planInterval): string
    {
        return array_search($planInterval, self::INTERVALS, true) ?: 'monthly';
    }
}
