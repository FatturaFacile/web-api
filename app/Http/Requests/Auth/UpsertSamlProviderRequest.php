<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Auth\Sso\ProvisionableRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The shape of the SAML provider settings of a tenant. The identity provider comes from a metadata URL, pasted metadata
 * XML, or typed fields; the URLs are checked again, with the server-side-request-forgery rules, by SsoUrlGuard.
 */
class UpsertSamlProviderRequest extends FormRequest
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
        $roles = Rule::in(ProvisionableRoles::all());

        return [
            'name' => ['required', 'string', 'max:120'],
            'idp_metadata_url' => ['nullable', 'string', 'url', 'max:2000'],
            'idp_metadata_xml' => ['nullable', 'string', 'max:1048576'],
            'idp_entity_id' => ['nullable', 'string', 'max:500'],
            'idp_sso_url' => ['nullable', 'string', 'url', 'max:2000'],
            'idp_slo_url' => ['nullable', 'string', 'url', 'max:2000'],
            'idp_certificates' => ['nullable', 'array', 'max:5'],
            'idp_certificates.*' => ['string', 'max:10000'],
            'email_attribute' => ['nullable', 'string', 'max:255'],
            'name_attribute' => ['nullable', 'string', 'max:255'],
            'role_attribute' => ['nullable', 'string', 'max:255'],
            'role_mapping' => ['nullable', 'array', 'max:100'],
            'role_mapping.*' => ['string', $roles],
            'default_role' => ['nullable', 'string', $roles],
            'sync_role_on_login' => ['sometimes', 'boolean'],
            'jit_provisioning' => ['sometimes', 'boolean'],
            'allowed_email_domains' => ['required', 'array', 'min:1', 'max:50'],
            'allowed_email_domains.*' => ['string', 'max:253', 'regex:/^(?!-)[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/'],
            'sp_private_key' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'sp_certificate' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $typed = filled($this->input('idp_entity_id')) && filled($this->input('idp_sso_url')) && filled($this->input('idp_certificates'));

                if (blank($this->input('idp_metadata_url')) && blank($this->input('idp_metadata_xml')) && ! $typed) {
                    $validator->errors()->add('idp_metadata_url', __('enterprise_auth.errors.saml_source_required'));
                }

                if ($this->boolean('jit_provisioning') && blank($this->input('default_role')) && (blank($this->input('role_attribute')) || blank($this->input('role_mapping')))) {
                    $validator->errors()->add('default_role', __('enterprise_auth.errors.jit_needs_role'));
                }

                if (filled($this->input('sp_private_key')) !== filled($this->input('sp_certificate')) && ($this->has('sp_private_key') || $this->has('sp_certificate'))) {
                    $validator->errors()->add('sp_certificate', __('enterprise_auth.errors.sp_key_pair'));
                }
            },
        ];
    }
}
