<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Services\TimeTracking\SwissHolidayCalendar;
use Modules\HumanResources\Services\TimeTracking\TimeRecordingMode;

final class SaveTimeProfileRequest extends FormRequest
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
            'mode' => ['required', Rule::enum(TimeRecordingMode::class)],
            'agreement_date' => ['nullable', 'date_format:Y-m-d'],
            'legal_max_weekly_hours' => ['required', 'integer', Rule::in([45, 50])],
            'weekly_hours' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:168'],
            'work_days_per_week' => ['required', 'integer', 'between:1,7'],
            'hire_date' => ['nullable', 'date_format:Y-m-d'],
            'termination_date' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('hire_date'), 'after_or_equal:hire_date')],
            'canton' => ['nullable', Rule::in(SwissHolidayCalendar::CANTONS)],
            'reason' => ['nullable', 'string', 'min:3', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('canton'))) {
            $this->merge(['canton' => strtoupper(trim($this->input('canton')))]);
        }
    }
}
