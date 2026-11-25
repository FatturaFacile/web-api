<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ElectronicSignature\Models\SignatureProviderConfig;

/**
 * A provider configuration. The credentials are never returned: only which ones are set.
 *
 * @mixin SignatureProviderConfig
 */
final class ProviderConfigResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $credentials = $this->credentials ?? [];

        return [
            'id' => $this->id,
            'provider' => $this->provider->value,
            'jurisdiction' => $this->jurisdiction->value,
            'name' => $this->name,
            'is_sandbox' => $this->is_sandbox,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'has_certificate' => $this->certificate_path !== null && $this->certificate_path !== '',
            'configured_credentials' => array_keys(array_filter($credentials, static fn (mixed $value): bool => is_string($value) && $value !== '')),
            'settings' => $this->settings,
            'last_tested_at' => $this->last_tested_at?->toIso8601String(),
            'last_test_status' => $this->last_test_status,
            'last_test_message' => $this->last_test_message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
