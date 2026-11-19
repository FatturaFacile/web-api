<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TravelExpense\Models\TravelPerDiemDay;

/**
 * The per-diem allowance of one day of a trip.
 *
 * @mixin TravelPerDiemDay
 */
final class PerDiemDayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'day' => $this->day->toDateString(),
            'hours_away' => $this->hours_away,
            'day_factor' => $this->day_factor,
            'breakfast_provided' => $this->breakfast_provided,
            'lunch_provided' => $this->lunch_provided,
            'dinner_provided' => $this->dinner_provided,
            'lodging_provided' => $this->lodging_provided,
            'currency' => $this->currency,
            'gross_amount' => $this->gross_amount,
            'deduction_amount' => $this->deduction_amount,
            'amount' => $this->amount,
            'tax_free_amount' => $this->tax_free_amount,
            'taxable_amount' => $this->taxable_amount,
        ];
    }
}
