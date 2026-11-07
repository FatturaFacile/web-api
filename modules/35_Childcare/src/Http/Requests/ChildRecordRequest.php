<?php

declare(strict_types=1);

namespace Modules\Childcare\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Childcare\Models\WeeklyPlan;

/**
 * Master data: groups, children, reference persons, weekly plans, fee assignments, document requirements and documents.
 * Each route names the payload it validates.
 */
final class ChildRecordRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return match (str_replace('api.v1.childcare.', '', (string) $this->route()?->getName())) {
            'groups.store' => ['name' => ['required', 'string', 'max:100'], 'capacity' => ['sometimes', 'integer', 'min:0', 'max:1000']],
            'children.store', 'children.update' => [
                'group_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('cc_groups')],
                'billing_contact_id' => [$presence, 'uuid', $this->tenantOwns('contacts')],
                'first_name' => [$presence, 'string', 'max:100'],
                'last_name' => [$presence, 'string', 'max:100'],
                'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
                'allergies' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'dietary_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'health_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'subsidy_canton' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
                'subsidy_inputs' => ['sometimes', 'nullable', 'array'],
                'subsidy_inputs.*' => ['nullable', 'numeric'],
                'enrolled_on' => [$presence, 'date'],
                'left_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:enrolled_on'],
                'status' => ['sometimes', 'string', 'in:active,left'],
            ],
            'children.reference-persons.store' => [
                'full_name' => ['required', 'string', 'max:150'],
                'phone' => ['nullable', 'string', 'max:40'],
                'email' => ['nullable', 'email', 'max:180'],
                'relationship' => ['required', 'string', 'in:mother,father,guardian,grandparent,relative,other'],
                'is_guardian' => ['required', 'boolean'],
                'can_pick_up' => ['required', 'boolean'],
                'portal_contact_id' => ['nullable', 'uuid', $this->tenantOwns('contacts')],
            ],
            'children.weekly-plans.store' => [
                'valid_from' => ['required', 'date'],
                'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
                'days' => ['required', 'array:'.implode(',', WeeklyPlan::Weekdays), 'min:1'],
                'days.*.from' => ['required', 'date_format:H:i'],
                'days.*.to' => ['required', 'date_format:H:i'],
                'days.*.meals' => ['sometimes', 'array'],
                'days.*.meals.*' => ['string', Rule::in(WeeklyPlan::Meals)],
            ],
            'children.fees.store' => [
                'fee_plan_id' => ['required', 'uuid', $this->tenantOwns('cc_fee_plans')],
                'valid_from' => ['required', 'date'],
                'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            ],
            'document-requirements.store' => [
                'name' => ['required', 'string', 'max:150'],
                'requires_signature' => ['sometimes', 'boolean'],
                'is_mandatory' => ['sometimes', 'boolean'],
            ],
            'children.documents.store' => [
                'title' => ['required', 'string', 'max:200'],
                'requirement_id' => ['nullable', 'uuid', $this->tenantOwns('cc_document_requirements')],
                'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            ],
            default => [],
        };
    }
}
