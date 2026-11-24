<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Services\TimeTracking\TimeEventType;
use Modules\HumanResources\Services\TimeTracking\TimePunchImportService;
use Modules\HumanResources\Services\TimeTracking\TimeRecordSource;

final class ImportTimePunchesRequest extends FormRequest
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
            'reader_id' => ['required', 'string', 'max:120'],
            'source' => ['sometimes', Rule::in([TimeRecordSource::Badge->value, TimeRecordSource::Terminal->value])],
            'punches' => ['required', 'array', 'min:1', 'max:5000'],
            'punches.*.external_id' => ['required', 'string', 'max:190'],
            'punches.*.badge_code' => ['nullable', 'string', 'max:120', 'required_without:punches.*.employee_id'],
            'punches.*.employee_id' => ['nullable', 'uuid'],
            'punches.*.occurred_at' => ['required', 'date'],
            'punches.*.punch_type' => ['required', Rule::in([...array_column(TimeEventType::cases(), 'value'), TimePunchImportService::MealPunch])],
            'punches.*.meal_type' => ['nullable', 'string', 'max:30'],
        ];
    }
}
