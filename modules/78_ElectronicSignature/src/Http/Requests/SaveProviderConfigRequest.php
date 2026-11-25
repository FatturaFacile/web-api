<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\ElectronicSignature\Enums\ProviderJurisdiction;
use Modules\ElectronicSignature\Enums\ProviderType;
use Modules\ElectronicSignature\Models\SignatureProviderConfig;

/**
 * Create or update a provider configuration. Credentials are optional on update: a key that is left out keeps its stored value.
 */
final class SaveProviderConfigRequest extends FormRequest
{
    /** Credential keys each provider understands; anything else is refused. */
    public const array CredentialKeys = [
        'native' => ['certificate_password', 'sms_sender'],
        'swisscom' => ['claimed_identity', 'claimed_identity_qes', 'client_certificate', 'client_key', 'client_key_password', 'distinguished_name_suffix', 'webhook_secret'],
        'infocert' => ['client_id', 'client_secret', 'signer_alias', 'webhook_secret'],
        'namirial' => ['api_key', 'webhook_secret'],
    ];

    public function authorize(): bool
    {
        return Gate::allows('signatures.configure');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => [$this->isMethod('POST') ? 'required' : 'sometimes', Rule::enum(ProviderType::class)],
            'jurisdiction' => ['required', Rule::enum(ProviderJurisdiction::class)],
            'name' => ['required', 'string', 'max:160'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:8000'],
            'settings' => ['nullable', 'array'],
            'settings.base_url' => ['nullable', 'url:https', 'max:300'],
            'settings.endpoints' => ['nullable', 'array'],
            'settings.endpoints.*' => ['nullable', 'string', 'max:200'],
            'is_sandbox' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
            'certificate' => ['nullable', 'file', 'max:512', 'extensions:p12,pfx'],
            'certificate_password' => ['nullable', 'string', 'max:200', 'required_with:certificate'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Multipart bodies carry booleans as strings.
        foreach (['is_sandbox', 'is_active', 'is_default'] as $flag) {
            if ($this->has($flag)) {
                $this->merge([$flag => filter_var($this->input($flag), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $this->input($flag)]);
            }
        }
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function ($validator): void {
            $provider = (string) ($this->input('provider') ?? SignatureProviderConfig::query()->find($this->route('id'))?->provider->value ?? '');
            $allowed = self::CredentialKeys[$provider] ?? null;

            if ($allowed === null) {
                return;
            }

            foreach (array_keys((array) $this->input('credentials', [])) as $key) {
                if (! in_array($key, $allowed, true)) {
                    $validator->errors()->add('credentials.'.$key, 'unknown_credential');
                }
            }
        }];
    }
}
