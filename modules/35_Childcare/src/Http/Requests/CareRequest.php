<?php

declare(strict_types=1);

namespace Modules\Childcare\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Childcare\Models\Attendance;
use Modules\Childcare\Models\Note;

/**
 * Daily care: attendance, corrections, register, notes, meals, staff shifts, activities and messages. Each route names
 * the payload it validates.
 */
final class CareRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Always allowed here: who may call the endpoint is decided by the module.access middleware and the policy check
     * of the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $child = ['required', 'uuid', $this->tenantOwns('cc_children')];
        $month = ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'];

        return match (str_replace('api.v1.childcare.', '', (string) $this->route()?->getName())) {
            'attendances.check-in' => ['child_id' => $child, 'at' => ['nullable', 'date'], 'temperature' => ['nullable', 'decimal:0,1', 'between:34,43']],
            'attendances.check-out' => ['child_id' => $child, 'at' => ['nullable', 'date'], 'picked_up_by' => ['required', 'uuid', $this->tenantOwns('cc_reference_persons')]],
            'attendances.absence' => ['child_id' => $child, 'date' => ['required', 'date'], 'reason' => ['required', 'string', 'in:sick,holiday,other']],
            'attendances.correct' => [
                'reason' => ['required', 'string', 'min:5', 'max:500'],
                'status' => ['sometimes', 'string', 'in:present,absent'],
                'absence_reason' => ['sometimes', 'nullable', 'string', 'in:sick,holiday,other'],
                'check_in_at' => ['sometimes', 'nullable', 'date'],
                'check_out_at' => ['sometimes', 'nullable', 'date'],
                'temperature' => ['sometimes', 'nullable', 'decimal:0,1', 'between:34,43'],
            ],
            'attendances.register', 'staff-hours' => ['month' => $month, 'group_id' => ['nullable', 'uuid', $this->tenantOwns('cc_groups')]],
            'notes.index' => ['child_id' => $child, 'date' => ['nullable', 'date']],
            'notes.store' => [
                'child_id' => $child,
                'date' => ['required', 'date'],
                'category' => ['required', Rule::in(Note::Categories)],
                'body' => ['required', 'string', 'max:5000'],
                'visible_to_family' => ['sometimes', 'boolean'],
            ],
            'meals' => ['date' => ['required', 'date']],
            'staff-shifts.store' => [
                'user_id' => ['required', 'uuid', $this->tenantMember()],
                'group_id' => ['nullable', 'uuid', $this->tenantOwns('cc_groups')],
                'date' => ['required', 'date'],
                'starts_at' => ['required', 'date_format:H:i'],
                'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
                'break_minutes' => ['sometimes', 'integer', 'min:0', 'max:600'],
            ],
            'activities.store' => [
                'name' => ['required', 'string', 'max:150'],
                'weekday' => ['required', 'integer', 'between:1,7'],
                'starts_at' => ['required', 'date_format:H:i'],
                'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
                'capacity' => ['sometimes', 'integer', 'min:0'],
                'monthly_fee' => ['sometimes', 'decimal:0,2', 'min:0'],
                'tax_rate' => ['sometimes', 'decimal:0,2', 'min:0', 'max:100'],
            ],
            'activities.enrol' => ['child_id' => $child, 'valid_from' => ['required', 'date'], 'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from']],
            'children.messages.store' => ['body' => ['required', 'string', 'max:5000']],
            default => [],
        };
    }

    /**
     * Only the correctable fields of an attendance.
     *
     * @return array<string, mixed>
     */
    public function corrections(): array
    {
        return array_intersect_key($this->validated(), array_flip(Attendance::CorrectableFields));
    }
}
