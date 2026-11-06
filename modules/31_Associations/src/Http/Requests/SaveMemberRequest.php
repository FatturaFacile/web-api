<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Enums\MemberStatus;
use Modules\Associations\Support\TenantRules;

final class SaveMemberRequest extends FormRequest
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
        $isCreation = $this->isMethod('post');
        $needsName = $isCreation ? ['required_without_all:contact_id,company_name'] : ['sometimes'];

        return [
            'contact_id' => $isCreation ? ['sometimes', 'nullable', 'uuid', TenantRules::exists('contacts')] : ['prohibited'],
            'first_name' => [...$needsName, 'nullable', 'string', 'max:255'],
            'last_name' => [...$needsName, 'nullable', 'string', 'max:255'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'status' => ['sometimes', Rule::enum(MemberStatus::class)],
            'family_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_families')],
            'joined_on' => ['sometimes', 'nullable', 'date'],
            'left_on' => ['sometimes', 'nullable', 'date'],
            'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
            'birth_place' => ['sometimes', 'nullable', 'string', 'max:255'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:20'],
            'tax_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'identity_document' => ['sometimes', 'nullable', 'string', 'max:255'],
            'health_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'allergies' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'emergency_contact' => ['sometimes', 'nullable', 'string', 'max:500'],
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'custom_fields' => ['sometimes', 'array'],
        ];
    }
}
