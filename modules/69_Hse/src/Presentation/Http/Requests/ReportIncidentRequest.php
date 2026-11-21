<?php

declare(strict_types=1);

namespace Modules\Hse\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Hse\Domain\Enums\IncidentType;

final class ReportIncidentRequest extends FormRequest
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
        return [
            'incident_type' => ['required', Rule::enum(IncidentType::class)],
            'incident_date' => ['required', 'date'],
            'location_or_site_id' => ['nullable', 'string', 'max:96'],
            'work_center_id' => ['nullable', 'uuid'],
            'involved_employee_ids' => ['nullable', 'array'],
            'involved_employee_ids.*' => ['uuid'],
            'witness_employee_ids' => ['nullable', 'array'],
            'witness_employee_ids.*' => ['uuid'],
            'description' => ['required', 'string', 'max:5000'],
            'immediate_actions_taken' => ['nullable', 'string', 'max:5000'],
            'prognosis_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'injury_dynamics' => ['nullable', 'array'],
            'injury_dynamics.activityAtTimeOfAccident' => ['required_with:injury_dynamics', 'string', 'max:255'],
            'injury_dynamics.deviatingEvent' => ['required_with:injury_dynamics', 'string', 'max:32'],
            'injury_dynamics.materialAgent' => ['required_with:injury_dynamics', 'string', 'max:32'],
            'injury_dynamics.injuredBodyPartCode' => ['required_with:injury_dynamics', 'string', 'max:32'],
            'injury_dynamics.lesionNatureCode' => ['required_with:injury_dynamics', 'string', 'max:32'],
            'root_cause_analysis' => ['nullable', 'array'],
            'corrective_actions' => ['nullable', 'array'],
            'corrective_actions.*.description' => ['required', 'string', 'max:1000'],
            'corrective_actions.*.assignee_id' => ['nullable', 'uuid'],
            'corrective_actions.*.due_date' => ['nullable', 'date'],
            'corrective_actions.*.verification_status' => ['nullable', 'string', 'in:open,done,verified'],
        ];
    }
}
