<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Services\TimeTracking\SwissHolidayCalendar;

final class TimeHolidaysRequest extends FormRequest
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
            'canton' => ['nullable', Rule::in(SwissHolidayCalendar::CANTONS)],
            'year' => ['nullable', 'integer', 'between:1900,2200'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('canton'))) {
            $this->merge(['canton' => strtoupper(trim($this->input('canton')))]);
        }
    }
}
