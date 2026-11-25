<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * Finds the budget that governs the spend of a date: the latest approved (or locked) revision of the budget of the
 * fiscal year that contains the date, else its approved original budget. Forecasts, rolling forecasts and scenarios
 * never govern the spend.
 */
final class BaselineResolver
{
    public function forDate(string $tenantId, Carbon $date): ?BudgetVersion
    {
        $day = $date->copy()->startOfDay();

        $candidates = BudgetVersion::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('version_type', [BudgetVersion::Budget, BudgetVersion::Revised])
            ->whereIn('status', [BudgetVersion::Approved, BudgetVersion::Locked])
            ->whereDate('year_start', '<=', $day->toDateString())
            ->whereDate('year_start', '>', $day->copy()->subMonths(BudgetPeriods::Count)->toDateString())
            ->get();

        return $candidates
            ->sort(static fn (BudgetVersion $left, BudgetVersion $right): int => [$right->version_type === BudgetVersion::Revised ? 1 : 0, $right->revision_number, $right->approved_at->timestamp ?? 0]
                <=> [$left->version_type === BudgetVersion::Revised ? 1 : 0, $left->revision_number, $left->approved_at->timestamp ?? 0])
            ->first();
    }

    /**
     * The currency the budget is kept in: the one of the governing version, else the default currency of the company.
     */
    public function currency(string $tenantId, ?BudgetVersion $version): string
    {
        if ($version !== null) {
            return $version->currency;
        }

        return (string) (DB::table('tenants')->where('id', $tenantId)->value('default_currency') ?? 'EUR');
    }
}
