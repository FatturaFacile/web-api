<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Presentation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RequestShiftSwapRequest extends FormRequest
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
            'shift_assignment_id' => ['required', 'uuid', Rule::exists('roster_shift_assignments', 'id')->where('tenant_id', $tenantId)],
            'target_peer_employee_id' => ['nullable', 'uuid', Rule::exists('hr_employees', 'id')->where('tenant_id', $tenantId)],
            'target_shift_assignment_id' => ['nullable', 'uuid', Rule::exists('roster_shift_assignments', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
