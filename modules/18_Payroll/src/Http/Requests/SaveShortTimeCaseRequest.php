<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Http\Requests\TenantScopedRules;
use Modules\Payroll\Models\ShortTimeCase;

final class SaveShortTimeCaseRequest extends FormRequest
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
            'kind' => ['required', Rule::in(ShortTimeCase::Kinds)],
            'department_id' => ['nullable', 'uuid', TenantScopedRules::exists('hr_departments')],
            'cause' => ['required', 'string', 'max:2000'],
            'notified_on' => ['nullable', 'date_format:Y-m-d'],
            'is_urgent' => ['sometimes', 'boolean'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'authority_reference' => ['nullable', 'string', 'max:120'],
        ];
    }
}
