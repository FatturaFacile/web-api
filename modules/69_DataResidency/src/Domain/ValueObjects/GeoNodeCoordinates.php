<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\ValueObjects;

/**
 * The physical location and the certifications of one sovereign node.
 */
final readonly class GeoNodeCoordinates
{
    /**
     * @param  list<string>  $certifications
     */
    public function __construct(
        public string $nodeCode,
        public string $regionCode,
        public string $countryIso2,
        public string $cantonOrRegion,
        public string $city,
        public string $providerName,
        public array $certifications,
        public ?float $latitude,
        public ?float $longitude,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'node_code' => $this->nodeCode,
            'region_code' => $this->regionCode,
            'country_iso2' => $this->countryIso2,
            'canton_or_region' => $this->cantonOrRegion,
            'city' => $this->city,
            'provider_name' => $this->providerName,
            'certifications' => $this->certifications,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}
