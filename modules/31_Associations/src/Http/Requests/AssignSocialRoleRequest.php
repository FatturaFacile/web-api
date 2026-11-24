<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Associations\Support\TenantRules;

final class AssignSocialRoleRequest extends FormRequest
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
            'social_role_id' => ['required', 'uuid', TenantRules::exists('assoc_social_roles')],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
