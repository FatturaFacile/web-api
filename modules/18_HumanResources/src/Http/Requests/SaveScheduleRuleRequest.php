<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Services\TimeTracking\PunchRoundingMode;

final class SaveScheduleRuleRequest extends FormRequest
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
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'tolerance_before_minutes' => ['sometimes', 'integer', 'between:0,120'],
            'tolerance_after_minutes' => ['sometimes', 'integer', 'between:0,120'],
            'rounding_minutes' => ['sometimes', 'integer', 'between:0,60'],
            'rounding_mode' => ['sometimes', Rule::enum(PunchRoundingMode::class)],
            'night_allowance_percent' => ['sometimes', 'nullable', 'numeric', 'between:0,300'],
            'sunday_allowance_percent' => ['sometimes', 'nullable', 'numeric', 'between:0,300'],
            'holiday_allowance_percent' => ['sometimes', 'nullable', 'numeric', 'between:0,300'],
            'daily_allowance_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'daily_allowance_minimum_minutes' => ['sometimes', 'integer', 'between:0,1440'],
        ];
    }
}
