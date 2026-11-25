<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\ExpenseManagement\Domain\Enums\ReportStatus;

/**
 * An expense report of an employee for a period, with its lines, per-diem allowances, payouts and audit trail.
 *
 * @property string $report_number
 * @property string|null $title
 * @property string|null $company_id
 * @property string|null $employee_id
 * @property string $user_id
 * @property string|null $cost_center_id
 * @property string $jurisdiction
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $currency
 * @property string $total_amount
 * @property string $approved_amount
 * @property string $reimbursable_amount
 * @property string $non_reimbursable_amount
 * @property string $taxable_amount
 * @property ReportStatus $status
 * @property Carbon|null $submitted_at
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $rejection_reason
 * @property Carbon|null $posted_at
 * @property Carbon|null $reimbursed_at
 * @property Carbon|null $cancelled_at
 */
final class ExpenseReport extends BaseEnterpriseModel
{
    protected $table = 'exm_reports';

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => ReportStatus::class,
            'total_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'reimbursable_amount' => 'decimal:2',
            'non_reimbursable_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'reimbursed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ExpenseLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class, 'expense_report_id')->orderBy('expense_date')->orderBy('created_at');
    }

    /**
     * @return HasMany<PerDiemAllowance, $this>
     */
    public function perDiems(): HasMany
    {
        return $this->hasMany(PerDiemAllowance::class, 'expense_report_id')->orderBy('trip_start_at');
    }

    /**
     * @return HasMany<ExpensePayout, $this>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(ExpensePayout::class, 'expense_report_id')->latest();
    }

    /**
     * @return HasMany<ExpenseAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(ExpenseAuditLog::class, 'expense_report_id')->orderBy('created_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function claimant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
