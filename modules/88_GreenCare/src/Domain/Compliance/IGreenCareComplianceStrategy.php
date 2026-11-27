<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Compliance;

use Illuminate\Support\Collection;
use Modules\GreenCare\Domain\Enums\LicenseType;
use Modules\GreenCare\Models\PhytosanitaryTreatmentLog;

/**
 * Rules of a jurisdiction (Switzerland, Italy) for the phytosanitary diary, the green waste and the withholding of the condominiums.
 */
interface IGreenCareComplianceStrategy
{
    /**
     * ISO code of the jurisdiction the strategy serves (IT, CH).
     */
    public function jurisdiction(): string;

    /**
     * Operator certifications that authorise the application of phytosanitary products in the jurisdiction.
     *
     * @return list<LicenseType>
     */
    public function acceptedLicenseTypes(): array;

    /**
     * Whether the registration number of the product has the shape the authority issues (UFAG/BLW W-number, Ministero della Salute number).
     */
    public function isValidRegistrationNumber(string $number): bool;

    public function registrationNumberHint(): string;

    /**
     * Code of the biodegradable green waste (CER 20 02 01, OTR / LVA 20 02 01).
     */
    public function defaultWasteCode(): string;

    /**
     * @return list<array{code: string, description: string}>
     */
    public function wasteCodes(): array;

    public function isValidWasteCode(string $code): bool;

    /**
     * Withholding applied to the invoices of the condominiums, or null when the jurisdiction has none.
     *
     * @return array{percent: float, type: string, reason: string, legal_basis: string}|null
     */
    public function condominiumWithholding(): ?array;

    /**
     * Hourly rates (regia) by role of the operator.
     *
     * @return array<string, float>
     */
    public function regiaHourlyRates(): array;

    /**
     * Botanical catalog offered when the inventory of a site is compiled.
     *
     * @return list<array{species: string, common_name: string}>
     */
    public function botanicalCatalog(): array;

    /**
     * Rows of the register of the phytosanitary treatments in the format of the authority (Quaderno di Campagna in Italy, Behandlungsjournal in Switzerland).
     * Column titles are translation keys (green_care.register.*), so the document follows the language of the user.
     *
     * @param  Collection<int, PhytosanitaryTreatmentLog>  $logs
     * @return array{title: string, columns: list<string>, rows: list<list<string>>}
     */
    public function registerExport(Collection $logs): array;
}
