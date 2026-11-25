<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\ValueObjects;

use Modules\PassengerTransport\Domain\Enums\VehicleCategory;

/**
 * What the fare and VAT of a passenger transport depend on: the agreed net fare and the kilometres by territory.
 */
final readonly class VatInput
{
    public function __construct(
        public string $netAmount,
        public string $currency,
        public string $totalKm,
        public string $itKm,
        public string $chKm,
        public string $otherEuKm,
        public ?VehicleCategory $vehicleCategory = null,
        public bool $municipalUrbanService = false,
        public bool $art23Exemption = false,
    ) {}
}
