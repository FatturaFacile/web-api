<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings\Access;

use App\Services\Tenancy\CustomRoleService;
use App\Services\Tenancy\TenantMembershipService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A custom role: name, description, the system role it is based on and the module levels it sets.
 */
class SaveCustomRoleRequest extends FormRequest
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
        $creating = $this->isMethod('POST');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'base_role' => [$creating ? 'required' : 'sometimes', 'string', Rule::in(app(CustomRoleService::class)->baseRoles())],
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string', Rule::in(app(TenantMembershipService::class)->levels())],
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
