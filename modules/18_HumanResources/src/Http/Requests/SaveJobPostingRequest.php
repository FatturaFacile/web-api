<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Models\JobPosting;

final class SaveJobPostingRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:20000'],
            'department_id' => ['nullable', 'uuid', TenantScopedRules::exists('hr_departments')],
            'position_id' => ['nullable', 'uuid', TenantScopedRules::exists('hr_positions')],
            'location' => ['nullable', 'string', 'max:120'],
            'employment_type' => ['sometimes', 'string', 'max:40'],
            'status' => ['sometimes', Rule::in([JobPosting::StatusDraft, JobPosting::StatusOpen])],
            'opens_on' => ['nullable', 'date_format:Y-m-d'],
            'closes_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:opens_on'],
        ];
    }
}
