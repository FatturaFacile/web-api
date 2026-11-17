<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantMembershipService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Role and module access of a member of a tenant. `modules` maps a module of config/permissions.php to a level
 * (default, blocked, read, write); a module that is absent keeps its level.
 */
class SaveTenantMemberRequest extends FormRequest
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

        return [
            'email' => [$this->routeIs('settings.users.store') ? 'required' : 'prohibited', 'email:rfc', 'max:255'],
            'role' => ['sometimes', 'string', Rule::in($memberships->roles())],
            // Wins over `role`; null detaches the member from its custom role.
            'custom_role_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('tenant_custom_roles', 'id')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')],
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string', Rule::in($memberships->levels())],
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

    /**
     * false when the request does not mention the custom role, null to detach it, otherwise the id.
     */
    public function customRoleId(): string|false|null
    {
        return $this->has('custom_role_id') ? ($this->input('custom_role_id') === null ? null : (string) $this->input('custom_role_id')) : false;
    }

    /**
     * @return array<string, string>|null
     */
    public function modules(): ?array
    {
        return $this->has('modules') ? (array) $this->input('modules') : null;
    }
}
