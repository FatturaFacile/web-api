<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ItalianPayroll\Domain\Enums\CausalCode;
use Modules\ItalianPayroll\Domain\Exceptions\PayrollPeriodLockedException;

/**
 * One attendance line of the LUL (libro unico del lavoro) for an employee: a causal with its hours or days, the meal
 * vouchers, the expense reimbursement and the fringe benefit of the month. It cannot change once the period leaves
 * the open attendance state.
 *
 * @property string $id
 * @property string $payroll_period_id
 * @property string $employee_id
 * @property string|null $matricola_inps
 * @property CausalCode $causal_code
 * @property string $hours_or_days
 * @property int $meal_vouchers_count
 * @property string $reimbursement_amount
 * @property string $fringe_benefit_amount
 * @property string|null $inps_protocol_number
 */
final class LulAttendanceExportRecord extends BaseEnterpriseModel
{
    protected $table = 'ipp_lul_records';

    protected static function booted(): void
    {
        $guard = static function (self $record): void {
            $period = ItalianPayrollPeriod::query()->find($record->payroll_period_id);

            if ($period !== null && ! $period->status->allowsAttendanceChanges()) {
                throw new PayrollPeriodLockedException("Il periodo {$period->month}/{$period->year} è {$period->status->value}: le presenze non sono più modificabili.");
            }
        };

        self::saving($guard);
        self::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'causal_code' => CausalCode::class,
            'meal_vouchers_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ItalianPayrollPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(ItalianPayrollPeriod::class, 'payroll_period_id');
    }
}
