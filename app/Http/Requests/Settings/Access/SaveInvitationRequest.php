<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings\Access;

use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantMembershipService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An e-mail invitation: the address, the role (or custom role), module levels of the member and the teams to join.
 */
class SaveInvitationRequest extends FormRequest
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
        $memberships = app(TenantMembershipService::class);
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['sometimes', 'string', Rule::in(array_values(array_diff($memberships->roles(), ['owner'])))],
            'custom_role_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('tenant_custom_roles', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string', Rule::in($memberships->levels())],
            'team_ids' => ['sometimes', 'array'],
            'team_ids.*' => ['uuid', Rule::exists('access_teams', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $unknown = array_diff(array_keys((array) $this->input('modules', [])), app(TenantMembershipService::class)->modules());

                foreach ($unknown as $module) {
                    $validator->errors()->add("modules.{$module}", "The module {$module} does not exist.");
                }
            },
        ];
    }
}
