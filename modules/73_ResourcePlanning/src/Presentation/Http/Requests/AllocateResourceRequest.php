<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Presentation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ResourcePlanning\Domain\Enums\BookingType;

/**
 * Books an employee on a project or holds them for an opportunity.
 */
final class AllocateResourceRequest extends FormRequest
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
            'employee_id' => ['required', 'uuid', Rule::exists('hr_employees', 'id')->where('tenant_id', $tenantId)],
            'booking_type' => ['required', Rule::enum(BookingType::class)],
            'project_id' => ['nullable', 'uuid', 'required_if:booking_type,'.BookingType::HardCommitted->value, Rule::exists('projects', 'id')->where('tenant_id', $tenantId)],
            'wbs_node_id' => ['nullable', 'uuid'],
            'role_id' => ['nullable', 'uuid'],
            'crm_opportunity_id' => ['nullable', 'uuid', Rule::exists('crm_opportunities', 'id')->where('tenant_id', $tenantId)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'allocated_hours_per_day' => ['required', 'numeric', 'gt:0', 'max:24'],
            'is_billable' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allow_overload' => ['sometimes', 'boolean'],
            'required_skill_ids' => ['sometimes', 'array', 'max:20'],
            'required_skill_ids.*' => ['uuid'],
        ];
    }
}
