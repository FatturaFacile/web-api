<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Support\TenantRules;

final class AddParticipantsRequest extends FormRequest
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
            'source' => ['required', Rule::in(['members', 'board', 'registry_type', 'selected'])],
            'member_ids' => ['required_if:source,selected', 'array', 'max:5000'],
            'member_ids.*' => ['uuid', TenantRules::exists('assoc_members')],
            'registry_type_id' => ['required_if:source,registry_type', 'nullable', 'uuid', TenantRules::exists('assoc_registry_types')],
            'social_role_ids' => ['sometimes', 'array', 'max:100'],
            'social_role_ids.*' => ['uuid', TenantRules::exists('assoc_social_roles')],
            'eligible' => ['sometimes', 'boolean'],
        ];
    }
}
