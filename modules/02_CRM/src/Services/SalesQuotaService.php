<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Modules\CRM\Models\AccountTerritory;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\SalesQuota;

/**
 * Attainment of a sales quota: the amount of the opportunities the user won in the period (restricted to the accounts
 * of the territory when the quota has one).
 */
final class SalesQuotaService
{
    /**
     * @return array{target: string, achieved: string, remaining: string, attainment_percent: string, won_count: int}
     */
    public function attainment(SalesQuota $quota): array
    {
        $query = Opportunity::query()
            ->where('tenant_id', $quota->tenant_id)
            ->where('status', 'won')
            ->where('owner_user_id', $quota->user_id)
            ->whereDate('actual_close_date', '>=', $quota->period_start->toDateString())
            ->whereDate('actual_close_date', '<=', $quota->period_end->toDateString());

        if ($quota->territory_id !== null) {
            $query->whereIn('account_id', AccountTerritory::query()->where('territory_id', $quota->territory_id)->select('account_id'));
        }

        $achieved = number_format((float) $query->sum('amount'), 2, '.', '');
        $target = number_format((float) $quota->target_amount, 2, '.', '');
        $remaining = bccomp($target, $achieved, 2) > 0 ? bcsub($target, $achieved, 2) : '0.00';
        $percent = bccomp($target, '0', 2) > 0 ? bcdiv(bcmul($achieved, '100', 4), $target, 2) : '0.00';

        return ['target' => $target, 'achieved' => $achieved, 'remaining' => $remaining, 'attainment_percent' => $percent, 'won_count' => $query->count()];
    }
}
