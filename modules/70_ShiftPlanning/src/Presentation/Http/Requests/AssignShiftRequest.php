<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Presentation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates a shift from a template (POST) or assigns/moves an existing one (PATCH: the period comes from the shift).
 */
final class AssignShiftRequest extends FormRequest
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
            'roster_schedule_period_id' => [$this->route('id') === null ? 'required' : 'nullable', 'uuid', Rule::exists('roster_schedule_periods', 'id')->where('tenant_id', $tenantId)],
            'shift_date' => ['required', 'date_format:Y-m-d'],
            'shift_template_id' => ['required', 'uuid', Rule::exists('roster_shift_templates', 'id')->where('tenant_id', $tenantId)],
            'employee_id' => ['nullable', 'uuid', Rule::exists('hr_employees', 'id')->where('tenant_id', $tenantId)],
            'required_role_or_skill_id' => ['nullable', 'uuid'],
        ];
    }
}
