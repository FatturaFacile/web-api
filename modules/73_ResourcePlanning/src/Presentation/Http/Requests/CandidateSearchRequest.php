<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Presentation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Searches the employees that fit the skills and the availability a role needs.
 */
final class CandidateSearchRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'allocated_hours_per_day' => ['required', 'numeric', 'gt:0', 'max:24'],
            'requirements' => ['present', 'array', 'max:20'],
            'requirements.*.skill_id' => ['required', 'uuid', Rule::exists('rp_skills', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'requirements.*.min_level' => ['required', 'integer', 'between:1,5'],
            'project_id' => ['nullable', 'uuid'],
            'wbs_node_id' => ['nullable', 'uuid'],
            'department_id' => ['nullable', 'uuid'],
            'require_valid_certificates' => ['sometimes', 'boolean'],
            'include_ineligible' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }
}
