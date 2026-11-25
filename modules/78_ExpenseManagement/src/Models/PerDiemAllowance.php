<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\ExpenseManagement\Domain\Enums\AllowanceMethod;
use Modules\ExpenseManagement\Domain\Enums\DestinationType;

/**
 * @property string $expense_report_id
 * @property string $country_code
 * @property DestinationType $destination_type
 * @property AllowanceMethod $allowance_method
 * @property Carbon $trip_start_at
 * @property Carbon $trip_end_at
 * @property string $duration_hours
 * @property string $days_count
 * @property string $allowance_rate_per_day
 * @property string $gross_allowance_amount
 * @property string $daily_tax_free_limit
 * @property string $tax_free_amount
 * @property string $taxable_excess_amount
 * @property string $meals_lodging_reductions
 * @property bool $lodging_reimbursed
 * @property bool $meals_reimbursed
 * @property list<array<string, mixed>>|null $warnings
 */
final class PerDiemAllowance extends BaseEnterpriseModel
{
    protected $table = 'exm_per_diems';

    /**
     * @return BelongsTo<ExpenseReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(ExpenseReport::class, 'expense_report_id');
    }

    protected function casts(): array
    {
        return [
            'destination_type' => DestinationType::class,
            'allowance_method' => AllowanceMethod::class,
            'trip_start_at' => 'datetime',
            'trip_end_at' => 'datetime',
            'duration_hours' => 'decimal:2',
            'days_count' => 'decimal:2',
            'allowance_rate_per_day' => 'decimal:2',
            'gross_allowance_amount' => 'decimal:2',
            'daily_tax_free_limit' => 'decimal:2',
            'tax_free_amount' => 'decimal:2',
            'taxable_excess_amount' => 'decimal:2',
            'meals_lodging_reductions' => 'decimal:2',
            'lodging_reimbursed' => 'boolean',
            'meals_reimbursed' => 'boolean',
            'warnings' => 'array',
        ];
    }
}
