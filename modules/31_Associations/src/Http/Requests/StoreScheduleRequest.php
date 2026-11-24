<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Enums\ScheduleOccurrence;
use Modules\Associations\Support\TenantRules;

final class StoreScheduleRequest extends FormRequest
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
            'pattern' => ['required', Rule::enum(ScheduleOccurrence::class)],
            'weekday' => ['required_if:pattern,weekday', 'nullable', 'integer', 'between:1,7'],
            'on_date' => ['required_if:pattern,date', 'nullable', 'date'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'resource_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_resources')],
        ];
    }
}
