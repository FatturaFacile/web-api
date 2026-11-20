<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationConfiguration;

/**
 * Credentials are write-only: only their presence is reported.
 *
 * @mixin PreservationConfiguration
 */
final class PreservationConfigurationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'jurisdiction' => $this->jurisdiction->value,
            'provider' => $this->provider->value,
            'has_credentials' => ! empty($this->credentials),
            'preservation_officer_name' => $this->preservation_officer_name,
            'preservation_officer_tax_code' => $this->preservation_officer_tax_code,
            'tsa_url' => $this->tsa_url,
            'has_tsa_credentials' => ! empty($this->tsa_credentials),
            'auto_seal_frequency' => $this->auto_seal_frequency->value,
            'default_retention_years' => $this->default_retention_years,
            'is_active' => $this->is_active,
        ];
    }
}
