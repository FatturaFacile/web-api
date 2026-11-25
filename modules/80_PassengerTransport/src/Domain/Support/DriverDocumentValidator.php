<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Support;

use DateTimeImmutable;
use Modules\PassengerTransport\Domain\Enums\IssueSeverity;
use Modules\PassengerTransport\Domain\Enums\VehicleCategory;
use Modules\PassengerTransport\Domain\ValueObjects\ComplianceIssue;

/**
 * Checks, on the date of the trip, the licence (B or D/DE), the CQC Persone (Italy), the CZV (Switzerland) and the tachograph
 * card of a driver against the vehicle and the territories the trip crosses.
 */
final class DriverDocumentValidator
{
    private const int EXPIRY_WARNING_DAYS = 60;

    /**
     * @param  list<string>  $licenseCategories
     * @return list<ComplianceIssue>
     */
    public function validate(
        VehicleCategory $category,
        bool $vehicleHasTachograph,
        array $licenseCategories,
        ?DateTimeImmutable $licenseExpiry,
        ?DateTimeImmutable $cqcExpiry,
        ?DateTimeImmutable $czvExpiry,
        ?DateTimeImmutable $tachographCardExpiry,
        DateTimeImmutable $tripDate,
        bool $tripTouchesIt,
        bool $tripTouchesCh,
    ): array {
        $issues = [];
        $categories = array_map('strtoupper', $licenseCategories);
        $needsD = $category->isBusLike();

        $issues = [...$issues, ...$this->expiry('license', $licenseExpiry, $tripDate, 'Art. 115 D.Lgs. 285/1992 / Art. 10 SVG')];

        if ($needsD && ! array_intersect(['D', 'DE', 'D1', 'D1E'], $categories)) {
            $issues[] = new ComplianceIssue('license_category_missing', IssueSeverity::Blocking, 'Art. 116 D.Lgs. 285/1992 / Art. 3 VZV', ['required' => 'D']);
        } elseif (! $needsD && ! array_intersect(['B', 'D', 'DE', 'BE'], $categories)) {
            $issues[] = new ComplianceIssue('license_category_missing', IssueSeverity::Blocking, 'Art. 116 D.Lgs. 285/1992 / Art. 3 VZV', ['required' => 'B']);
        }

        if ($needsD) {
            if ($tripTouchesIt) {
                $issues = [...$issues, ...$this->expiry('cqc', $cqcExpiry, $tripDate, 'D.Lgs. 286/2005 (CQC Persone)')];
            }

            if ($tripTouchesCh) {
                $issues = [...$issues, ...$this->expiry('czv', $czvExpiry, $tripDate, 'Art. 3 ff. VZV / Verordnung über die Zulassung als Strassentransportunternehmen (CZV)')];
            }
        }

        if ($vehicleHasTachograph) {
            $issues = [...$issues, ...$this->expiry('tachograph_card', $tachographCardExpiry, $tripDate, 'Art. 27 Reg. (EU) 165/2014')];
        }

        return $issues;
    }

    /**
     * @return list<ComplianceIssue>
     */
    private function expiry(string $document, ?DateTimeImmutable $expiry, DateTimeImmutable $tripDate, string $citation): array
    {
        if ($expiry === null) {
            return [new ComplianceIssue($document.'_missing', IssueSeverity::Blocking, $citation)];
        }

        if ($expiry->format('Y-m-d') < $tripDate->format('Y-m-d')) {
            return [new ComplianceIssue($document.'_expired', IssueSeverity::Blocking, $citation, ['expired_on' => $expiry->format('Y-m-d'), 'trip_date' => $tripDate->format('Y-m-d')])];
        }

        if ($expiry < $tripDate->modify('+'.self::EXPIRY_WARNING_DAYS.' days')) {
            return [new ComplianceIssue($document.'_expiring', IssueSeverity::Warning, $citation, ['expires_on' => $expiry->format('Y-m-d')])];
        }

        return [];
    }
}
