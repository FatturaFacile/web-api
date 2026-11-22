<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Presentation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ResourcePlanning\Domain\Enums\BookingType;

/**
 * The query string of the read endpoints: a window of dates and the usual filters. The heatmap adds the granularity and a
 * list of employees, the report a grouping, the allocations list a booking type.
 */
final class PlanningWindowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `employee_id=<uuid>` and `employee_id[]=<uuid>` both select employees.
     */
    protected function prepareForValidation(): void
    {
        $employees = $this->input('employee_id');

        if (is_string($employees) && $employees !== '') {
            $this->merge(['employee_id' => [$employees]]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'granularity' => ['sometimes', Rule::in(['week', 'day'])],
            'group_by' => ['sometimes', Rule::in(['resource', 'department', 'cost_center'])],
            'department_id' => ['sometimes', 'uuid'],
            'employee_id' => ['sometimes', 'array'],
            'employee_id.*' => ['uuid', Rule::exists('hr_employees', 'id')->where('tenant_id', $tenantId)],
            'project_id' => ['sometimes', 'uuid'],
            'booking_type' => ['sometimes', Rule::enum(BookingType::class)],
            'skill_id' => ['sometimes', 'uuid'],
            'category' => ['sometimes', 'string', 'max:60'],
            'country' => ['sometimes', 'string', 'size:2'],
            'region' => ['sometimes', 'string', 'max:10'],
            'municipality' => ['sometimes', 'string', 'max:120'],
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
        ];
    }
}
