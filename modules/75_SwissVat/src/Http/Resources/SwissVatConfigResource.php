<?php

declare(strict_types=1);

namespace Modules\SwissVat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SwissVat\Models\SwissVatConfig;

/**
 * @mixin SwissVatConfig
 */
final class SwissVatConfigResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'tenant_id' => $this->tenant_id ? (string) $this->tenant_id : null,
            'uid_mwst' => $this->uid_mwst,
            'accounting_method' => $this->accounting_method,
            'consideration_basis' => $this->consideration_basis,
            'reporting_frequency' => $this->reporting_frequency,
            'sss_rate_primary' => $this->sss_rate_primary !== null ? (string) $this->sss_rate_primary : null,
            'sss_rate_secondary' => $this->sss_rate_secondary !== null ? (string) $this->sss_rate_secondary : null,
            'sss_sector_primary' => $this->sss_sector_primary,
            'sss_sector_secondary' => $this->sss_sector_secondary,
            'rounding_mode' => $this->rounding_mode,
            'auto_detect_bezugsteuer' => (bool) $this->auto_detect_bezugsteuer,
            'bezugsteuer_threshold_chf' => (string) $this->bezugsteuer_threshold_chf,
            'default_currency' => $this->default_currency,
            'canton' => $this->canton,
            'gl_accounts' => $this->gl_accounts ?? [],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
