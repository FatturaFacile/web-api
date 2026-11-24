<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Calculation of the per-diem days. Without days the meals and lodging already recorded are kept; with days, each one
 * names the meals and lodging the employer provided on that date (the dates not listed provide nothing).
 */
final class PerDiemRequest extends FormRequest
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
            'days' => ['nullable', 'array', 'max:366'],
            'days.*.day' => ['required_with:days', 'date_format:Y-m-d', 'distinct'],
            'days.*.breakfast_provided' => ['nullable', 'boolean'],
            'days.*.lunch_provided' => ['nullable', 'boolean'],
            'days.*.dinner_provided' => ['nullable', 'boolean'],
            'days.*.lodging_provided' => ['nullable', 'boolean'],
        ];
    }
}
