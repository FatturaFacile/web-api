<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Presentation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ShiftPlanning\Domain\Enums\Jurisdiction;
use Modules\ShiftPlanning\Domain\Enums\PeriodType;
use Modules\ShiftPlanning\Domain\Enums\RotationPattern;

final class GenerateRosterRequest extends FormRequest
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
            'location_or_department_id' => ['nullable', 'uuid'],
            'production_line_id' => ['nullable', 'uuid'],
            'period_type' => ['required', Rule::enum(PeriodType::class)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'jurisdiction' => ['nullable', Rule::enum(Jurisdiction::class)],
            'pattern' => ['nullable', 'required_without:source_period_id', Rule::enum(RotationPattern::class)],
            'source_period_id' => ['nullable', 'uuid', Rule::exists('roster_schedule_periods', 'id')->where('tenant_id', $tenantId)],
            'employee_ids' => ['nullable', 'required_with:pattern', 'array', 'min:1'],
            'employee_ids.*' => ['uuid', Rule::exists('hr_employees', 'id')->where('tenant_id', $tenantId)],
            'shift_template_ids' => ['nullable', 'required_with:pattern', 'array', 'min:1'],
            'shift_template_ids.*' => ['uuid', Rule::exists('roster_shift_templates', 'id')->where('tenant_id', $tenantId)],
            'open_slots' => ['nullable', 'array'],
            'open_slots.*' => ['integer', 'min:1', 'max:50'],
        ];
    }
}
