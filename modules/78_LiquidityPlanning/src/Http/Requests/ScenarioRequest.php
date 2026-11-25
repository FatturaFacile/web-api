<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A saved what-if scenario: a name and the levers.
 */
final class ScenarioRequest extends FormRequest
{
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
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'levers' => ['required', 'array'],
            'levers.collection_delay_days' => ['nullable', 'integer', 'between:0,180'],
            'levers.collection_delay_share_percent' => ['nullable', 'integer', 'between:0,100'],
            'levers.inflow_change_percent' => ['nullable', 'numeric', 'between:-100,100'],
            'levers.outflow_change_percent' => ['nullable', 'numeric', 'between:-100,300'],
            'levers.raw_material_increase_percent' => ['nullable', 'numeric', 'between:0,300'],
            'levers.capex_acceleration_days' => ['nullable', 'integer', 'between:0,365'],
            'levers.capex_change_percent' => ['nullable', 'numeric', 'between:-100,300'],
            'levers.additional_financing_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'levers.additional_financing_date' => ['nullable', 'date'],
        ];
    }
}
