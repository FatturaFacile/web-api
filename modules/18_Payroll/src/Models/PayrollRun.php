<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Enterprise\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Payroll\Exceptions\PayrollRunClosedException;

/**
 * The elaboration of a month. Closed runs are immutable; the only way back is markReopened().
 *
 * @property Carbon|null $approved_at
 * @property string|null $approved_by
 */
final class PayrollRun extends PayrollRecord
{
    use Auditable;

    public const string StatusDraft = 'Draft';

    public const string StatusCalculated = 'Calculated';

    public const string StatusApproved = 'Approved';

    public const string StatusFinal = 'Final';

    protected $table = 'payroll_runs';

    private bool $isBeingReopened = false;

    protected static function booted(): void
    {
        self::updating(static function (self $run): void {
            if ($run->getOriginal('status') === self::StatusFinal && ! $run->isBeingReopened) {
                throw PayrollRunClosedException::forPeriod((int) $run->year, (int) $run->month);
            }
        });

        self::deleting(static function (self $run): void {
            if ($run->isFinal()) {
                throw PayrollRunClosedException::forPeriod((int) $run->year, (int) $run->month);
            }
        });
    }

    /**
     * Totals of the payslips of the run, as grid columns (the list loads the same values with withCount/withSum).
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        $payslips = 'SELECT %s FROM payroll_payslips WHERE payroll_payslips.payroll_run_id = payroll_runs.id';

        return [
            ...parent::gridColumns(),
            'employees_count' => sprintf($payslips, 'COUNT(*)'),
            'total_gross' => sprintf($payslips, 'SUM(payroll_payslips.gross_salary)'),
            'total_net' => sprintf($payslips, 'SUM(payroll_payslips.net_salary)'),
        ];
    }

    public function isFinal(): bool
    {
        return $this->status === self::StatusFinal;
    }

    /**
     * Back to draft: only PayrollRunService does this, after checking the rules and with the database guard lifted.
     */
    public function markReopened(string $actorId, string $reason): void
    {
        $this->isBeingReopened = true;

        try {
            $this->forceFill([
                'status' => self::StatusDraft,
                'finalized_at' => null,
                'finalized_by' => null,
                'reopened_at' => now(),
                'reopened_by' => $actorId,
                'reopen_reason' => $reason,
                'reopen_count' => (int) $this->reopen_count + 1,
            ])->save();
        } finally {
            $this->isBeingReopened = false;
        }
    }

    /**
     * @return HasMany<Payslip, $this>
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'payroll_run_id');
    }

    /**
     * @return HasMany<PayrollRunEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(PayrollRunEvent::class, 'payroll_run_id')->orderBy('occurred_at')->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'payment_date' => 'date:Y-m-d',
            'calculated_at' => 'datetime',
            'approved_at' => 'datetime',
            'finalized_at' => 'datetime',
            'reopened_at' => 'datetime',
            'reopen_count' => 'integer',
        ];
    }
}
