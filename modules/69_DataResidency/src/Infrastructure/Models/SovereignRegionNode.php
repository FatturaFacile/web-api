<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Support\Facades\Crypt;
use Modules\DataResidency\Domain\Enums\SovereignServiceType;
use Modules\DataResidency\Domain\ValueObjects\GeoNodeCoordinates;

/**
 * One physical piece of sovereign infrastructure (a database, an object store, an AI endpoint...) in a known data
 * center. The platform catalogue is global (tenant_id null); a tenant may register a dedicated node of its own.
 *
 * @property string $id
 * @property string $node_code
 * @property SovereignServiceType $service_type
 * @property string $country_iso2
 * @property string $canton_or_region
 * @property string $city
 * @property string $provider_name
 * @property string|null $endpoint_dsn_encrypted
 * @property list<string>|null $certifications_json
 * @property float|null $latitude
 * @property float|null $longitude
 * @property bool $is_active
 */
final class SovereignRegionNode extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'core_sovereign_region_nodes';

    protected $hidden = ['endpoint_dsn_encrypted'];

    protected function casts(): array
    {
        return [
            'service_type' => SovereignServiceType::class,
            'certifications_json' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Stores the connection details (endpoint, region, bucket, DSN...) encrypted at rest.
     *
     * @param  array<string, mixed>  $endpoint
     */
    public function setEndpoint(array $endpoint): void
    {
        $this->endpoint_dsn_encrypted = Crypt::encryptString((string) json_encode($endpoint));
    }

    /**
     * @return array<string, mixed>
     */
    public function endpoint(): array
    {
        if ($this->endpoint_dsn_encrypted === null || $this->endpoint_dsn_encrypted === '') {
            return [];
        }

        $decoded = json_decode(Crypt::decryptString($this->endpoint_dsn_encrypted), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function coordinates(): GeoNodeCoordinates
    {
        $region = $this->endpoint()['region'] ?? $this->canton_or_region;

        return new GeoNodeCoordinates(
            $this->node_code,
            is_string($region) ? $region : $this->canton_or_region,
            $this->country_iso2,
            $this->canton_or_region,
            $this->city,
            $this->provider_name,
            $this->certifications_json ?? [],
            $this->latitude,
            $this->longitude,
        );
    }
}
