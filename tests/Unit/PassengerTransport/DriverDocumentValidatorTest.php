<?php

declare(strict_types=1);

use Modules\PassengerTransport\Domain\Enums\IssueSeverity;
use Modules\PassengerTransport\Domain\Enums\VehicleCategory;
use Modules\PassengerTransport\Domain\Support\DriverDocumentValidator;
use Modules\PassengerTransport\Domain\ValueObjects\ComplianceIssue;

/**
 * @param  list<string>  $categories
 * @return list<ComplianceIssue>
 */
function ptrDocuments(VehicleCategory $category, array $categories, ?string $license, ?string $cqc, ?string $czv, ?string $card, string $tripDate, bool $it, bool $ch, bool $tachograph = true): array
{
    $date = static fn (?string $value): ?DateTimeImmutable => $value === null ? null : new DateTimeImmutable($value);

    return (new DriverDocumentValidator)->validate($category, $tachograph, $categories, $date($license), $date($cqc), $date($czv), $date($card), new DateTimeImmutable($tripDate), $it, $ch);
}

/**
 * @param  list<ComplianceIssue>  $issues
 * @return list<string>
 */
function ptrDocumentCodes(array $issues, IssueSeverity $severity = IssueSeverity::Blocking): array
{
    return array_values(array_map(static fn (ComplianceIssue $issue): string => $issue->code, array_filter($issues, static fn (ComplianceIssue $issue): bool => $issue->severity === $severity)));
}

it('accepts a coach driver whose documents are valid on the day of the trip', function (): void {
    $issues = ptrDocuments(VehicleCategory::GtCoachOver35t, ['B', 'D', 'DE'], '2030-01-01', '2029-01-01', '2029-06-01', '2031-01-01', '2028-03-10', true, true);

    expect($issues)->toBe([]);
});

it('blocks a licence that expires before the trip date even if it is valid today', function (): void {
    $issues = ptrDocuments(VehicleCategory::SedanNcc, ['B'], '2028-03-09', null, null, null, '2028-03-10', true, false, false);

    expect(ptrDocumentCodes($issues))->toBe(['license_expired']);
});

it('blocks an expired or missing CQC Persone only when the trip touches Italy and the vehicle is a bus', function (): void {
    $coach = fn (?string $cqc, bool $it) => ptrDocuments(VehicleCategory::GtCoachOver35t, ['D'], '2030-01-01', $cqc, '2030-01-01', '2030-01-01', '2028-03-10', $it, false);

    expect(ptrDocumentCodes($coach('2028-03-01', true)))->toBe(['cqc_expired'])
        ->and(ptrDocumentCodes($coach(null, true)))->toBe(['cqc_missing'])
        ->and(ptrDocumentCodes($coach('2028-03-01', false)))->toBe([])
        ->and(ptrDocumentCodes(ptrDocuments(VehicleCategory::SedanNcc, ['B'], '2030-01-01', '2028-03-01', null, null, '2028-03-10', true, false, false)))->toBe([]);
});

it('blocks an expired CZV on a trip that touches Switzerland', function (): void {
    $issues = ptrDocuments(VehicleCategory::SchoolBus, ['D'], '2030-01-01', '2030-01-01', '2028-03-09', '2030-01-01', '2028-03-10', false, true);

    expect(ptrDocumentCodes($issues))->toBe(['czv_expired']);
});

it('blocks an expired tachograph card when the vehicle has a digital tachograph', function (): void {
    $withTachograph = ptrDocuments(VehicleCategory::GtCoachOver35t, ['D'], '2030-01-01', '2030-01-01', '2030-01-01', '2028-03-01', '2028-03-10', true, false);
    $without = ptrDocuments(VehicleCategory::SedanNcc, ['B'], '2030-01-01', null, null, '2028-03-01', '2028-03-10', true, false, false);

    expect(ptrDocumentCodes($withTachograph))->toBe(['tachograph_card_expired'])
        ->and(ptrDocumentCodes($without))->toBe([]);
});

it('requires the licence category of the vehicle: D for buses, B for cars', function (): void {
    $busWithB = ptrDocuments(VehicleCategory::Minibus916, ['B'], '2030-01-01', '2030-01-01', '2030-01-01', '2030-01-01', '2028-03-10', true, true);
    $carWithD = ptrDocuments(VehicleCategory::LuxuryVanNcc, ['D'], '2030-01-01', null, null, null, '2028-03-10', true, false, false);

    expect(ptrDocumentCodes($busWithB))->toBe(['license_category_missing'])
        ->and(ptrDocumentCodes($carWithD))->toBe([]);
});

it('warns when a document expires within sixty days of the trip', function (): void {
    $issues = ptrDocuments(VehicleCategory::SedanNcc, ['B'], '2028-04-15', null, null, null, '2028-03-10', true, false, false);

    expect(ptrDocumentCodes($issues, IssueSeverity::Warning))->toBe(['license_expiring'])
        ->and(ptrDocumentCodes($issues))->toBe([]);
});
