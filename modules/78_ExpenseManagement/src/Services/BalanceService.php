<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\ExpenseManagement\Domain\Enums\ReportStatus;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Models\PerDiemAllowance;
use Modules\ExpenseManagement\Support\Decimal;

/**
 * What an employee is owed or has open: open reports, approved amounts not yet reimbursed, advances of business trips not
 * yet reconciled and the taxable per-diem excess of the year.
 */
final class BalanceService
{
    /**
     * @return array<string, mixed>
     */
    public function forUser(string $userId, string $currency): array
    {
        $open = ExpenseReport::query()->where('user_id', $userId)->whereIn('status', [ReportStatus::Draft->value, ReportStatus::Submitted->value, ReportStatus::UnderReview->value, ReportStatus::Rejected->value]);
        $unpaid = ExpenseReport::query()->where('user_id', $userId)->whereIn('status', [ReportStatus::Approved->value, ReportStatus::Posted->value]);

        $perDiemTaxable = PerDiemAllowance::query()
            ->whereHas('report', fn ($reports) => $reports->where('user_id', $userId)->where('status', '!=', ReportStatus::Cancelled->value)->whereYear('period_start', now()->year))
            ->sum('taxable_excess_amount');

        return [
            'open_reports' => (clone $open)->count(),
            'open_amount' => Decimal::normalize((string) (clone $open)->sum('total_amount')),
            'approved_unpaid_amount' => Decimal::normalize((string) $unpaid->sum('approved_amount')),
            'advances_outstanding' => $this->advances($userId),
            'per_diem_taxable_ytd' => Decimal::normalize((string) $perDiemTaxable),
            'currency' => $currency,
        ];
    }

    private function advances(string $userId): string
    {
        if (! Schema::hasTable('travel_trips')) {
            return '0.00';
        }

        return Decimal::normalize((string) DB::table('travel_trips')->where('user_id', $userId)->whereNotNull('advance_paid_at')->whereNull('reconciled_at')->whereNull('deleted_at')->sum('advance_amount'));
    }
}
