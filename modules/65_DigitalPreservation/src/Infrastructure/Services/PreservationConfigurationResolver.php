<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Modules\DigitalPreservation\Domain\Enums\AutoSealFrequency;
use Modules\DigitalPreservation\Domain\Enums\JurisdictionMode;
use Modules\DigitalPreservation\Domain\Enums\PreservationProvider;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationConfiguration;

/**
 * The configuration of the active tenant, or the defaults (dual jurisdiction, local WORM vault, 10 years) when none was saved.
 */
final readonly class PreservationConfigurationResolver
{
    public function __construct(private TenantContext $tenantContext) {}

    public function current(): PreservationConfiguration
    {
        return PreservationConfiguration::first() ?? new PreservationConfiguration([
            'jurisdiction' => JurisdictionMode::Dual, 'provider' => PreservationProvider::LocalWorm,
            'auto_seal_frequency' => AutoSealFrequency::Monthly,
            'default_retention_years' => (int) config('digital_preservation.minimum_retention_years'), 'is_active' => true,
        ]);
    }

    /**
     * Producer data written to the PdV.xml.
     *
     * @return array{name: string, tax_code: ?string, vat: ?string}
     */
    public function producer(): array
    {
        $tenant = Tenant::find($this->tenantContext->getTenantId());

        return [
            'name' => (string) ($tenant?->legal_name ?: $tenant?->name),
            'tax_code' => $tenant?->fiscal_code,
            'vat' => $tenant?->vat_number,
        ];
    }
}
