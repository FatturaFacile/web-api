<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Associations\Support\TenantRules;

final class AssignFeeRequest extends FormRequest
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
            'member_id' => ['required', 'uuid', TenantRules::exists('assoc_members')],
            'fee_plan_id' => ['required', 'uuid', TenantRules::exists('assoc_fee_plans')],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'course_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_courses')],
            'membership_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_memberships')],
            'payer_contact_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('contacts')],
            'apply_family_discount' => ['sometimes', 'boolean'],
        ];
    }
}
