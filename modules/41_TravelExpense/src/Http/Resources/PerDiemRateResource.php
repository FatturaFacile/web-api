<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TravelExpense\Models\TravelPerDiemRate;

/**
 * A row of the per-diem rate table.
 *
 * @mixin TravelPerDiemRate
 */
final class PerDiemRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'jurisdiction' => $this->jurisdiction,
            'year' => $this->year,
            'scope' => $this->scope,
            'country' => $this->country,
            'currency' => $this->currency,
            'daily_amount' => $this->daily_amount,
            'tax_free_daily_amount' => $this->tax_free_daily_amount,
            'lodging_deduction_percent' => $this->lodging_deduction_percent,
            'breakfast_deduction_percent' => $this->breakfast_deduction_percent,
            'meal_deduction_percent' => $this->meal_deduction_percent,
            'deduct_each_meal' => $this->deduct_each_meal,
            'half_day_min_hours' => $this->half_day_min_hours,
            'full_day_min_hours' => $this->full_day_min_hours,
            'notes' => $this->notes,
        ];
    }
}
