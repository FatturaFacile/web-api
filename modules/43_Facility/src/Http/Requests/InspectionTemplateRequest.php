<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Facility\Http\Requests\Concerns\FacilityRules;
use Modules\Facility\Models\FacilityInspectionTemplate;
use Modules\Facility\Services\InspectionService;

/**
 * A recurring inspection: frequency, next due date and the checklist (items with a label and a type).
 */
final class InspectionTemplateRequest extends FormRequest
{
    use FacilityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'building_id' => ['nullable', 'uuid', $this->tenantOwns('fac_buildings')],
            'space_id' => ['nullable', 'uuid', $this->tenantOwns('fac_spaces')],
            'frequency' => ['required', Rule::in(FacilityInspectionTemplate::Frequencies)],
            'interval_count' => ['sometimes', 'integer', 'min:1', 'max:52'],
            'checklist' => ['required', 'array', 'min:1', 'max:100'],
            'checklist.*.key' => ['nullable', 'string', 'max:60'],
            'checklist.*.label' => ['required', 'string', 'max:190'],
            'checklist.*.type' => ['sometimes', Rule::in(FacilityInspectionTemplate::ItemTypes)],
            'checklist.*.required' => ['sometimes', 'boolean'],
            'assignee_id' => ['nullable', 'uuid', $this->tenantMember()],
            'next_due_date' => ['required', 'date_format:Y-m-d'],
            'lead_days' => ['sometimes', 'integer', 'min:0', 'max:'.InspectionService::MaxLeadDays],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
