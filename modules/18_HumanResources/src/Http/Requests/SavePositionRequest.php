<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A position of the org chart; every reference must belong to the active company.
 */
final class SavePositionRequest extends FormRequest
{
    /**
     * Module access is checked by the route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $unique = Rule::unique('hr_positions', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at');

        if ($this->route('id') !== null) {
            $unique->ignore((string) $this->route('id'));
        }

        return [
            'code' => [$required, 'string', 'max:60', $unique],
            'title' => [$required, 'string', 'max:150'],
            'department_id' => ['sometimes', 'nullable', 'uuid', TenantScopedRules::exists('hr_departments')],
            'job_grade_id' => ['sometimes', 'nullable', 'uuid', TenantScopedRules::exists('hr_job_grades')],
            'reports_to_position_id' => ['sometimes', 'nullable', 'uuid', TenantScopedRules::exists('hr_positions'), Rule::notIn([(string) $this->route('id')])],
            'headcount_budget' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_critical_role' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
