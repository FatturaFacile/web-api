<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Compliance;

use Illuminate\Support\Collection;
use Modules\GreenCare\Domain\Enums\LicenseType;
use Modules\GreenCare\Models\PhytosanitaryTreatmentLog;

/**
 * Switzerland: products are admitted by the FOAG (UFAG / BLW, number W-xxxx), professional users need the special authorisation for the
 * use of plant protection products (PER / PNRP, OPF and ORRChim), waste movements use the OTR codes and the hourly work of a garden
 * company is billed with the JardinSuisse "regia" tariff. No withholding applies to the invoices of the property managers.
 */
final class ChJardinSuisseUfamStrategy implements IGreenCareComplianceStrategy
{
    use BuildsTreatmentRegister;

    public function jurisdiction(): string
    {
        return 'CH';
    }

    public function acceptedLicenseTypes(): array
    {
        return [LicenseType::Per, LicenseType::Pnrp, LicenseType::Opf];
    }

    public function isValidRegistrationNumber(string $number): bool
    {
        // Federal admission number: W-6004, W-7235-1.
        return preg_match('/^W-\d{3,5}(-\d{1,2})?$/', strtoupper(trim($number))) === 1;
    }

    public function registrationNumberHint(): string
    {
        return 'W-1234';
    }

    public function defaultWasteCode(): string
    {
        return 'OTR 20 02 01';
    }

    public function wasteCodes(): array
    {
        return [
            ['code' => 'OTR 20 02 01', 'description' => 'Biodegradable waste (green cuttings, grass, leaves)'],
            ['code' => 'OTR 02 01 03', 'description' => 'Plant-tissue waste'],
            ['code' => 'OTR 20 02 02', 'description' => 'Soil and stones from gardens'],
            ['code' => 'OTR 20 02 03', 'description' => 'Other non-biodegradable waste from gardens'],
        ];
    }

    public function isValidWasteCode(string $code): bool
    {
        return preg_match('/^(OTR |LVA )?\d{2} \d{2} \d{2}$/', strtoupper(trim($code))) === 1;
    }

    public function condominiumWithholding(): ?array
    {
        return null;
    }

    public function regiaHourlyRates(): array
    {
        /** @var array<string, float> $rates */
        $rates = (array) config('green_care.regia_rates_chf', []);

        return array_map(static fn (mixed $rate): float => (float) $rate, $rates);
    }

    public function botanicalCatalog(): array
    {
        return [
            ['species' => 'Acer campestre', 'common_name' => 'Feldahorn'],
            ['species' => 'Buxus sempervirens', 'common_name' => 'Buchs'],
            ['species' => 'Carpinus betulus', 'common_name' => 'Hagebuche'],
            ['species' => 'Fagus sylvatica', 'common_name' => 'Rotbuche'],
            ['species' => 'Taxus baccata', 'common_name' => 'Eibe'],
            ['species' => 'Thuja occidentalis', 'common_name' => 'Lebensbaum'],
            ['species' => 'Prunus laurocerasus', 'common_name' => 'Kirschlorbeer'],
            ['species' => 'Lolium perenne', 'common_name' => 'Englisches Raigras'],
        ];
    }

    public function registerExport(Collection $logs): array
    {
        return $this->buildRegister($logs, 'green_care.register.title_ch');
    }
}
