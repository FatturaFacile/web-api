<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\LeaveManagement\Models\LeaveRequest;

/**
 * @mixin LeaveRequest
 */
final class LeaveRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_number' => $this->request_number,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => $this->employee?->full_name),
            'employee_number' => $this->whenLoaded('employee', fn () => $this->employee?->employee_number),
            'department_id' => $this->department_id,
            'department_name' => $this->whenLoaded('department', fn () => $this->department?->name),
            'policy_id' => $this->policy_id,
            'ledger_id' => $this->ledger_id,
            'country' => $this->country,
            'leave_type' => $this->leave_type,
            'start_date' => $this->start_date->format('Y-m-d'),
            'end_date' => $this->end_date->format('Y-m-d'),
            'half_day_or_hourly_duration' => $this->half_day_or_hourly_duration,
            'duration_unit' => $this->duration_unit,
            'requested_days' => (float) $this->requested_days,
            'requested_hours' => (float) $this->requested_hours,
            'status' => $this->status,
            'inps_medical_certificate_protocol_number' => $this->inps_medical_certificate_protocol_number,
            'suva_claim_number' => $this->suva_claim_number,
            'inail_claim_number' => $this->inail_claim_number,
            'medical_certificate_doc_id' => $this->medical_certificate_doc_id,
            'certificate_issue_date' => $this->certificate_issue_date?->format('Y-m-d'),
            'is_accident_occupational' => $this->is_accident_occupational,
            'is_fault_absence' => (bool) $this->is_fault_absence,
            'is_pregnancy_related' => (bool) $this->is_pregnancy_related,
            'ijm_ktg_waiting_days' => $this->ijm_ktg_waiting_days,
            'ipg_eo_allowance_code' => $this->ipg_eo_allowance_code,
            'legge_104_beneficiary_ref' => $this->legge_104_beneficiary_ref,
            'indemnity_breakdown' => $this->indemnity_breakdown,
            'staffing_check_result' => $this->staffing_check_result,
            'workflow_instance_id' => $this->workflow_instance_id,
            'is_locked' => (bool) $this->is_locked,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'notes' => $this->notes,
        ];
    }
}
