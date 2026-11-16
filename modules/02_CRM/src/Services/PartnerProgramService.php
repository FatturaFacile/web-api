<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Illuminate\Support\Facades\DB;
use Modules\CRM\Models\DealRegistration;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\Partner;
use Modules\CRM\Models\PartnerTier;

/**
 * Partner program: the annual revenue of a partner is the amount of its won deal registrations of the last 12 months
 * (the amount of the linked opportunity when there is one, else the expected amount); the tier is the highest rank
 * whose minimum annual revenue is reached.
 */
final class PartnerProgramService
{
    public function annualRevenue(Partner $partner): string
    {
        $total = '0.00';

        DealRegistration::query()
            ->where('tenant_id', $partner->tenant_id)
            ->where('partner_id', $partner->id)
            ->where('status', 'won')
            ->where('registered_on', '>=', now()->subYear()->toDateString())
            ->get()
            ->each(function (DealRegistration $registration) use (&$total): void {
                $amount = $registration->opportunity_id !== null
                    ? Opportunity::query()->where('tenant_id', $registration->tenant_id)->whereKey($registration->opportunity_id)->value('amount')
                    : null;
                $total = bcadd($total, number_format((float) ($amount ?? $registration->expected_amount), 2, '.', ''), 2);
            });

        return $total;
    }

    /**
     * Recomputes the revenue and the tier of one partner; returns whether the tier changed.
     */
    public function evaluateTier(Partner $partner): bool
    {
        $revenue = $this->annualRevenue($partner);
        $tier = PartnerTier::query()
            ->where('tenant_id', $partner->tenant_id)
            ->where('min_annual_revenue', '<=', $revenue)
            ->orderByDesc('rank')
            ->first();
        $changed = $tier?->id !== $partner->tier_id;

        $partner->forceFill(['closed_revenue' => $revenue, 'tier_id' => $tier?->id])->save();

        return $changed;
    }

    /**
     * Evaluates every active partner of the tenant; returns the number of partners whose tier changed.
     */
    public function evaluateAll(string $tenantId): int
    {
        return DB::transaction(function () use ($tenantId): int {
            $changed = 0;

            Partner::query()->where('tenant_id', $tenantId)->where('status', 'active')->each(function (Partner $partner) use (&$changed): void {
                $changed += $this->evaluateTier($partner) ? 1 : 0;
            });

            return $changed;
        });
    }
}
