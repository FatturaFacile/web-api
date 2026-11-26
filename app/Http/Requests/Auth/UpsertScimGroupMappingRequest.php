<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Auth\Sso\ProvisionableRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The mapping of a SCIM group (by display name) to a role of the tenant. The owner role is never allowed.
 */
class UpsertScimGroupMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'group_name' => ['required', 'string', 'min:1', 'max:255'],
            'role' => ['required', 'string', Rule::in(ProvisionableRoles::all())],
        ];
    }
}
