<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A department of the org chart; every reference must belong to the active company.
 */
final class SaveDepartmentRequest extends FormRequest
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
        $unique = Rule::unique('hr_departments', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at');

        if ($this->route('id') !== null) {
            $unique->ignore((string) $this->route('id'));
        }

        return [
            'code' => [$required, 'string', 'max:60', $unique],
            'name' => [$required, 'string', 'max:150'],
            'parent_id' => ['sometimes', 'nullable', 'uuid', TenantScopedRules::exists('hr_departments'), Rule::notIn([(string) $this->route('id')])],
            'business_unit_id' => ['sometimes', 'nullable', 'uuid', TenantScopedRules::exists('hr_business_units')],
            'cost_center_id' => ['sometimes', 'nullable', 'uuid', TenantScopedRules::exists('hr_cost_centers')],
            'manager_employee_id' => ['sometimes', 'nullable', 'uuid', TenantScopedRules::exists('hr_employees')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
