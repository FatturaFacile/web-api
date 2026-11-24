<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\LeaveManagement\Domain\Enums\LeaveCountry;
use Modules\LeaveManagement\Domain\Enums\LeaveTypeCode;
use Modules\LeaveManagement\Models\LeaveRequest;

final class RegisterSickLeaveInpsRequest extends FormRequest
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
            'leave_type' => ['nullable', Rule::enum(LeaveTypeCode::class)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'half_day_or_hourly_duration' => ['nullable', 'string', 'max:40'],
            'requested_days' => ['nullable', 'numeric', 'min:0.05', 'max:366'],
            'requested_hours' => ['nullable', 'numeric', 'min:0.25', 'max:3000'],
            'inps_medical_certificate_protocol_number' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{9}$/'],
            'suva_claim_number' => ['nullable', 'string', 'max:60'],
            'inail_claim_number' => ['nullable', 'string', 'max:60'],
            'medical_certificate_doc_id' => ['nullable', 'uuid'],
            'certificate_issue_date' => ['nullable', 'date_format:Y-m-d'],
            'is_accident_occupational' => ['nullable', 'boolean'],
            'is_fault_absence' => ['nullable', 'boolean'],
            'is_pregnancy_related' => ['nullable', 'boolean'],
            'ijm_ktg_waiting_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'ipg_eo_allowance_code' => ['nullable', 'string', 'max:40'],
            'auto_approve' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
