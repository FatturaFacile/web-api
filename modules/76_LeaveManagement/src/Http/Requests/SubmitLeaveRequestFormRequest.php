<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\LeaveManagement\Domain\Enums\LeaveCountry;
use Modules\LeaveManagement\Domain\Enums\LeaveTypeCode;
use Modules\LeaveManagement\Domain\Enums\LeaveUnitMode;
use Modules\LeaveManagement\Models\LeaveRequest;

final class SubmitLeaveRequestFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employeeId = (string) $this->input('employee_id', '');

        return $employeeId !== '' && Gate::allows('submitForEmployee', [LeaveRequest::class, $employeeId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid'],
            'policy_id' => ['nullable', 'uuid'],
            'country' => ['nullable', Rule::enum(LeaveCountry::class)],
            'leave_type' => ['required', Rule::enum(LeaveTypeCode::class)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'half_day_or_hourly_duration' => ['nullable', 'string', 'max:40'],
            'duration_unit' => ['nullable', Rule::enum(LeaveUnitMode::class)],
            'requested_days' => ['nullable', 'numeric', 'min:0.05', 'max:366'],
            'requested_hours' => ['nullable', 'numeric', 'min:0.25', 'max:3000'],
            'legge_104_beneficiary_ref' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
