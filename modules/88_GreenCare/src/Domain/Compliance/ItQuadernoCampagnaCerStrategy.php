<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Compliance;

use Illuminate\Support\Collection;
use Modules\GreenCare\Domain\Enums\LicenseType;
use Modules\GreenCare\Models\PhytosanitaryTreatmentLog;

/**
 * Italy: the professional use of plant protection products needs the patentino of the PAN (D.Lgs. 150/2012), every treatment is written in
 * the Quaderno di Campagna with the registration number of the Ministero della Salute, the green waste is the CER 20 02 01 and the
 * condominiums withhold 4% on the invoices for works and services (Art. 25-ter DPR 600/73).
 */
final class ItQuadernoCampagnaCerStrategy implements IGreenCareComplianceStrategy
{
    use BuildsTreatmentRegister;

    public function jurisdiction(): string
    {
        return 'IT';
    }

    public function acceptedLicenseTypes(): array
    {
        return [LicenseType::Pan];
    }

    public function isValidRegistrationNumber(string $number): bool
    {
        // Registration number of the Ministero della Salute: 12345 or 12345/6.
        return preg_match('/^\d{1,6}(\/\d{1,3})?$/', trim($number)) === 1;
    }

    public function registrationNumberHint(): string
    {
        return '12345';
    }

    public function defaultWasteCode(): string
    {
        return 'CER 20 02 01';
    }

    public function wasteCodes(): array
    {
        return [
            ['code' => 'CER 20 02 01', 'description' => 'Biodegradable waste (green cuttings, grass, leaves)'],
            ['code' => 'CER 20 02 02', 'description' => 'Soil and stones from gardens'],
            ['code' => 'CER 20 02 03', 'description' => 'Other non-biodegradable waste from gardens'],
            ['code' => 'CER 02 01 03', 'description' => 'Plant-tissue waste'],
        ];
    }

    public function isValidWasteCode(string $code): bool
    {
        return preg_match('/^(CER )?\d{2} \d{2} \d{2}\*?$/', strtoupper(trim($code))) === 1;
    }

    public function condominiumWithholding(): ?array
    {
        /** @var array{percent: int|float|string, type: string, reason: string, legal_basis: string} $rule */
        $rule = (array) config('green_care.condominium_withholding');

        return [
            'percent' => (float) $rule['percent'],
            'type' => (string) $rule['type'],
            'reason' => (string) $rule['reason'],
            'legal_basis' => (string) $rule['legal_basis'],
        ];
    }

    public function regiaHourlyRates(): array
    {
        return [];
    }

    public function botanicalCatalog(): array
    {
        return [
            ['species' => 'Buxus sempervirens', 'common_name' => 'Bosso'],
            ['species' => 'Laurus nobilis', 'common_name' => 'Alloro'],
            ['species' => 'Photinia x fraseri', 'common_name' => 'Fotinia'],
            ['species' => 'Prunus laurocerasus', 'common_name' => 'Lauroceraso'],
            ['species' => 'Cupressus sempervirens', 'common_name' => 'Cipresso'],
            ['species' => 'Lagerstroemia indica', 'common_name' => 'Lagerstroemia'],
            ['species' => 'Festuca arundinacea', 'common_name' => 'Festuca'],
            ['species' => 'Cynodon dactylon', 'common_name' => 'Gramigna'],
        ];
    }

    public function registerExport(Collection $logs): array
    {
        return $this->buildRegister($logs, 'green_care.register.title_it');
    }
}
