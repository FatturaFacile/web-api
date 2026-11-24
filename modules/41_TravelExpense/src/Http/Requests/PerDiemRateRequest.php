<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\TravelExpense\Models\TravelPerDiemRate;
use Modules\TravelExpense\Models\TravelTrip;

/**
 * A row of the per-diem rate table: jurisdiction, year, scope, optional destination country, daily amount, tax-free
 * threshold and the deductions for the meals and lodging the employer provides.
 */
final class PerDiemRateRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'jurisdiction' => ['required', Rule::in(TravelTrip::Jurisdictions)],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'scope' => ['required', Rule::in(TravelPerDiemRate::Scopes)],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'currency' => ['required', Rule::in(['EUR', 'CHF'])],
            'daily_amount' => ['required', 'numeric', 'min:0', 'max:999999'],
            'tax_free_daily_amount' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'lodging_deduction_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'breakfast_deduction_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'meal_deduction_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'deduct_each_meal' => ['nullable', 'boolean'],
            'half_day_min_hours' => ['nullable', 'integer', 'min:1', 'max:24'],
            'full_day_min_hours' => ['nullable', 'integer', 'min:1', 'max:24', 'gte:half_day_min_hours'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The country is stored in capitals.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper($this->input('country'))]);
        }
    }
}
