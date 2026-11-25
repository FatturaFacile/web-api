<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ExpenseManagement\Models\PerDiemAllowance;

/**
 * @mixin PerDiemAllowance
 */
final class PerDiemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'country_code' => $this->country_code,
            'destination_type' => $this->destination_type->value,
            'allowance_method' => $this->allowance_method->value,
            'trip_start_at' => $this->trip_start_at->toIso8601String(),
            'trip_end_at' => $this->trip_end_at->toIso8601String(),
            'duration_hours' => $this->duration_hours,
            'days_count' => $this->days_count,
            'allowance_rate_per_day' => $this->allowance_rate_per_day,
            'gross_allowance_amount' => $this->gross_allowance_amount,
            'daily_tax_free_limit' => $this->daily_tax_free_limit,
            'tax_free_amount' => $this->tax_free_amount,
            'taxable_excess_amount' => $this->taxable_excess_amount,
            'meals_lodging_reductions' => $this->meals_lodging_reductions,
            'lodging_reimbursed' => $this->lodging_reimbursed,
            'meals_reimbursed' => $this->meals_reimbursed,
            'warnings' => $this->warnings ?? [],
        ];
    }
}
