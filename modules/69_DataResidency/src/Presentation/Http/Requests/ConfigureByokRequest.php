<?php

declare(strict_types=1);

namespace Modules\DataResidency\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\DataResidency\Domain\Enums\KeyManagementMode;
use Modules\DataResidency\Domain\Enums\KmsProviderType;

final class ConfigureByokRequest extends FormRequest
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
            'legal_entity_id' => ['nullable', 'uuid'],
            'key_management_mode' => ['required', Rule::enum(KeyManagementMode::class)],
            'provider_type' => ['required', Rule::enum(KmsProviderType::class)],
            'external_key_uri' => ['required', 'string', 'max:512'],
            'kmip_endpoint' => ['nullable', 'url:https', 'max:255'],
            'credentials' => ['nullable', 'array', 'max:12'],
            'credentials.*' => ['nullable', 'string', 'max:4096'],
            'dek_cache_ttl_seconds' => ['nullable', 'integer', 'min:0', 'max:900'],
            'rotation_interval_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'dry_run' => ['nullable', 'boolean'],
        ];
    }
}
