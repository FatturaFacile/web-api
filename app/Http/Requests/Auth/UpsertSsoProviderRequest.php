<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Auth\Sso\ProvisionableRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The shape of the OpenID Connect provider settings of a tenant. The endpoints are checked again, with the
 * server-side-request-forgery rules, by SsoUrlGuard when they are used.
 */
class UpsertSsoProviderRequest extends FormRequest
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
            'issuer' => ['required', 'string', 'url', 'max:500'],
            'use_discovery' => ['sometimes', 'boolean'],
            'authorization_endpoint' => ['nullable', 'string', 'url', 'max:2000'],
            'token_endpoint' => ['nullable', 'string', 'url', 'max:2000'],
            'jwks_uri' => ['nullable', 'string', 'url', 'max:2000'],
            'redirect_uri' => ['required', 'string', 'url', 'max:2000'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'scopes' => ['sometimes', 'string', 'max:255', 'regex:/(^|\s)openid(\s|$)/'],
            'allowed_email_domains' => ['required', 'array', 'min:1', 'max:50'],
            'allowed_email_domains.*' => ['string', 'max:253', 'regex:/^(?!-)[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/'],
            'role_claim' => ['nullable', 'string', 'max:120'],
            'role_mapping' => ['nullable', 'array', 'max:100'],
            'role_mapping.*' => ['string', $roles],
            'default_role' => ['nullable', 'string', $roles],
            'sync_role_on_login' => ['sometimes', 'boolean'],
            'jit_provisioning' => ['sometimes', 'boolean'],
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
                $insecure = (bool) config('sso.allow_insecure_endpoints');

                foreach (['issuer', 'redirect_uri', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
                    $value = $this->input($field);

                    if (is_string($value) && $value !== '' && ! $insecure && ! str_starts_with(strtolower($value), 'https://')) {
                        $validator->errors()->add($field, __('enterprise_auth.errors.https_required'));
                    }
                }

                $discovery = $this->boolean('use_discovery', true);

                if (! $discovery) {
                    foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
                        if (blank($this->input($field))) {
                            $validator->errors()->add($field, __('enterprise_auth.errors.endpoint_required'));
                        }
                    }
                }

                if ($this->boolean('jit_provisioning') && blank($this->input('default_role')) && (blank($this->input('role_claim')) || blank($this->input('role_mapping')))) {
                    $validator->errors()->add('default_role', __('enterprise_auth.errors.jit_needs_role'));
                }
            },
        ];
    }
}
