<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Presentation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Records (POST, an existing pair is updated) or changes (PUT: level and validity only) the proficiency of an employee.
 */
final class EmployeeSkillProfileRequest extends FormRequest
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
        $creating = $this->route('id') === null;

        return [
            'employee_id' => [$creating ? 'required' : 'prohibited', 'uuid', Rule::exists('hr_employees', 'id')->where('tenant_id', $tenantId)],
            'skill_id' => [$creating ? 'required' : 'prohibited', 'uuid', Rule::exists('rp_skills', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'proficiency_level' => ['required', 'integer', 'between:1,5'],
            'valid_until_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
