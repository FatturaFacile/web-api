<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Models\StaffingNeed;

final class SaveStaffingNeedRequest extends FormRequest
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
            'department_id' => ['required', 'uuid', TenantScopedRules::exists('hr_departments')],
            'shift_type_id' => ['required', 'uuid', TenantScopedRules::exists('hr_shift_types')],
            'day_kind' => ['required', Rule::in(StaffingNeed::DayKinds)],
            'required_count' => ['required', 'integer', 'between:0,1000'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ];
    }
}
