<?php

declare(strict_types=1);

use Modules\SkiOutdoorSchool\Domain\Enums\Discipline;
use Modules\SkiOutdoorSchool\Domain\Enums\LessonStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\PackageItemType;
use Modules\SkiOutdoorSchool\Domain\Enums\RetentionMode;
use Modules\SkiOutdoorSchool\Domain\Enums\VatRegime;
use Modules\SkiOutdoorSchool\Domain\Exceptions\SkiSchoolException;
use Modules\SkiOutdoorSchool\Domain\Services\FairRotationService;
use Modules\SkiOutdoorSchool\Domain\Services\LevelReassignmentService;
use Modules\SkiOutdoorSchool\Domain\Services\PackagePricingService;
use Modules\SkiOutdoorSchool\Domain\Services\PayoutStatementCalculator;
use Modules\SkiOutdoorSchool\Domain\Services\SecretariatCommissionCalculator;
use Modules\SkiOutdoorSchool\Domain\ValueObjects\InstructorCandidate;
use Modules\SkiOutdoorSchool\Domain\ValueObjects\TimeRange;

function skCandidate(string $id, float $hours = 0.0, array $overrides = []): InstructorCandidate
{
    $input = array_merge([
        'id' => $id, 'name' => 'Instructor '.$id, 'disciplines' => ['alpine_ski', 'snowboard'], 'languages' => ['IT', 'EN'],
        'available' => true, 'hoursInPeriod' => $hours, 'lastAssignedAt' => null, 'unavailableReason' => null,
    ], $overrides);

    return new InstructorCandidate(...$input);
}

function skErrorCode(callable $callback): ?string
{
    try {
        $callback();
    } catch (SkiSchoolException $exception) {
        return $exception->errorCode();
    }

    return null;
}

// --- Fair rotation -------------------------------------------------------------------------------------------------------------------------

test('the fair rotation ranks the eligible instructors by the lowest cumulative hours', function (): void {
    $ranked = (new FairRotationService)->rank([skCandidate('a', 12.0), skCandidate('b', 3.5), skCandidate('c', 8.0)], 'alpine_ski', 'it');

    expect(array_column($ranked, 'instructor_id'))->toBe(['b', 'c', 'a'])
        ->and(array_column($ranked, 'rank'))->toBe([1, 2, 3])
        ->and($ranked[0]['hours_in_period'])->toBe(3.5);
});

test('ties go to who has waited longest since the last assignment, then to the name', function (): void {
    $ranked = (new FairRotationService)->rank([
        skCandidate('a', 5.0, ['name' => 'Zeta', 'lastAssignedAt' => '2027-01-19 10:00:00']),
        skCandidate('b', 5.0, ['name' => 'Beta', 'lastAssignedAt' => '2027-01-18 10:00:00']),
        skCandidate('c', 5.0, ['name' => 'Alfa', 'lastAssignedAt' => '2027-01-18 10:00:00']),
        skCandidate('d', 5.0, ['name' => 'Never', 'lastAssignedAt' => null]),
    ], 'snowboard', 'EN');

    expect(array_column($ranked, 'instructor_id'))->toBe(['d', 'c', 'b', 'a']);
});

test('an instructor who does not teach the discipline, speak the language or is busy is ranked after the eligible ones with the reasons', function (): void {
    $ranked = (new FairRotationService)->rank([
        skCandidate('a', 0.0, ['disciplines' => ['telemark']]),
        skCandidate('b', 1.0, ['languages' => ['DE']]),
        skCandidate('c', 2.0, ['available' => false, 'unavailableReason' => 'lesson_conflict']),
        skCandidate('d', 40.0),
    ], 'alpine_ski', 'IT');

    expect($ranked[0]['instructor_id'])->toBe('d')->and($ranked[0]['eligible'])->toBeTrue()
        ->and(array_column(array_slice($ranked, 1), 'eligible'))->toBe([false, false, false])
        ->and($ranked[1]['reasons'])->toBe(['discipline_mismatch'])
        ->and($ranked[2]['reasons'])->toBe(['language_mismatch'])
        ->and($ranked[3]['reasons'])->toBe(['lesson_conflict'])
        ->and($ranked[1]['rank'])->toBeNull();
});

test('a requested instructor overrides the rotation while he or she can take the lesson', function (): void {
    $service = new FairRotationService;
    $candidates = [skCandidate('a', 1.0), skCandidate('b', 30.0)];

    expect($service->suggest($candidates, 'alpine_ski', 'IT'))->toBe('a')
        ->and($service->suggest($candidates, 'alpine_ski', 'IT', 'b'))->toBe('b')
        ->and($service->rank($candidates, 'alpine_ski', 'IT', 'b')[0]['is_requested'])->toBeTrue();
});

test('a requested instructor who cannot take the lesson is refused instead of silently replaced', function (): void {
    $candidates = [skCandidate('a', 1.0), skCandidate('b', 2.0, ['available' => false, 'unavailableReason' => 'blackout'])];

    expect(skErrorCode(fn () => (new FairRotationService)->suggest($candidates, 'alpine_ski', 'IT', 'b')))->toBe('requested_instructor_unavailable')
        ->and(skErrorCode(fn () => (new FairRotationService)->suggest([skCandidate('x', 0.0, ['languages' => ['FR']])], 'alpine_ski', 'IT')))->toBe('no_instructor_available');
});

// --- Time ranges ---------------------------------------------------------------------------------------------------------------------------

test('time ranges overlap only when they share minutes', function (): void {
    $lesson = TimeRange::fromStart('09:00', 55);

    expect($lesson->end())->toBe('09:55')
        ->and($lesson->overlaps(TimeRange::fromTimes('09:55', '10:25')))->toBeFalse()
        ->and($lesson->overlaps(TimeRange::fromTimes('09:30', '10:00')))->toBeTrue()
        ->and(TimeRange::fromTimes('08:00', '12:00')->contains($lesson))->toBeTrue()
        ->and(fn () => TimeRange::toMinutes('25:00'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new TimeRange(600, 600))->toThrow(InvalidArgumentException::class);
});

// --- Secretariat retention and payout ------------------------------------------------------------------------------------------------------

test('the gross fee is rate times minutes and the percent retention is taken from it, in whole cents', function (): void {
    $result = (new SecretariatCommissionCalculator)->calculate(55, 40.0, RetentionMode::PERCENT, 10.0);

    expect($result)->toBe(['gross_cents' => 3667, 'retention_cents' => 367, 'net_cents' => 3300]);
});

test('a fixed hourly retention is charged per worked hour and never exceeds the gross fee', function (): void {
    $calculator = new SecretariatCommissionCalculator;

    expect($calculator->calculate(120, 40.0, RetentionMode::FIXED_HOURLY, 5.0))->toBe(['gross_cents' => 8000, 'retention_cents' => 1000, 'net_cents' => 7000])
        ->and($calculator->calculate(60, 4.0, RetentionMode::FIXED_HOURLY, 9.0))->toBe(['gross_cents' => 400, 'retention_cents' => 400, 'net_cents' => 0])
        ->and($calculator->calculate(30, 60.0, RetentionMode::NONE, 50.0))->toBe(['gross_cents' => 3000, 'retention_cents' => 0, 'net_cents' => 3000]);
});

test('the payout totals split the hours, apply the retention and then the withholding on the fee after the retention', function (): void {
    $totals = (new PayoutStatementCalculator)->totals([
        ['source_type' => 'LESSON', 'hours' => 1.0, 'gross_cents' => 4000, 'retention_cents' => 400],
        ['source_type' => 'LESSON', 'hours' => 0.5, 'gross_cents' => 2000, 'retention_cents' => 200],
        ['source_type' => 'COURSE', 'hours' => 12.0, 'gross_cents' => 40000, 'retention_cents' => 4000],
    ], 20.0);

    expect($totals)->toBe([
        'hours_total' => 13.5, 'private_hours' => 1.5, 'group_hours' => 12.0, 'gross_cents' => 46000, 'retention_cents' => 4600,
        'withholding_cents' => 8280, 'net_cents' => 33120,
    ]);
});

// --- VAT exemption and package pricing -----------------------------------------------------------------------------------------------------

test('sports instruction is exempt in Italy with nature N4 and in Switzerland with the LIVA reference', function (): void {
    $italy = VatRegime::exemptionFor('IT');
    $switzerland = VatRegime::exemptionFor('ch');

    expect($italy)->toBe(VatRegime::IT_EXEMPT_ART10_N20)->and($italy->sdiNature())->toBe('N4')
        ->and($italy->legalReference())->toBe('Art. 10 n. 20 DPR 633/72')
        ->and($switzerland)->toBe(VatRegime::CH_EXEMPT_ART21_N11)->and($switzerland->sdiNature())->toBeNull()
        ->and($switzerland->legalReference())->toBe('Art. 21 cpv. 2 n. 11 LIVA')
        ->and(VatRegime::TAXABLE->isExempt())->toBeFalse()->and(VatRegime::TAXABLE->legalReference())->toBeNull();
});

test('a package puts the exempt instruction at zero VAT and taxes rental and skipass at the rate of the jurisdiction', function (): void {
    $pricing = new PackagePricingService;
    $items = [
        ['item_type' => 'RENTAL_SKI_KIT', 'description' => null, 'quantity' => 1.0, 'days' => 2, 'unit_price_net' => 35.0, 'vat_rate' => 22.0],
        ['item_type' => 'SKIPASS', 'description' => 'Skipass', 'quantity' => 1.0, 'days' => 1, 'unit_price_net' => 55.0, 'vat_rate' => 10.0],
    ];

    $lines = $pricing->lines('Private lesson', 90.0, VatRegime::IT_EXEMPT_ART10_N20, $items);

    expect($lines)->toHaveCount(3)
        ->and($lines[0]['tax_rate'])->toBe(0.0)->and($lines[0]['nature'])->toBe('N4')->and($lines[0]['kind'])->toBe('instruction')
        ->and($lines[0]['description'])->toContain('Art. 10 n. 20 DPR 633/72')
        ->and($lines[1]['quantity'])->toBe(2.0)->and($lines[1]['tax_rate'])->toBe(22.0)
        ->and($pricing->totals($lines))->toBe(['net_cents' => 21500, 'vat_cents' => 1540 + 550, 'gross_cents' => 21500 + 2090]);
});

test('the add-on rates come from the jurisdiction and the instruction of a taxable school is taxed', function (): void {
    $pricing = new PackagePricingService;
    $lines = $pricing->lines('Lesson', 100.0, VatRegime::TAXABLE, []);

    expect($pricing->addOnRate('IT', PackageItemType::SKIPASS))->toBe(10.0)
        ->and($pricing->addOnRate('CH', PackageItemType::ARTVA_KIT))->toBe(8.1)
        ->and($lines[0]['tax_rate'])->toBe(22.0)->and($lines[0]['nature'])->toBeNull();
});

// --- Level reassignment --------------------------------------------------------------------------------------------------------------------

function skGroups(array $overrides = []): array
{
    $base = ['capacity' => 2, 'enrolled' => 2, 'accepts' => true, 'discipline' => 'alpine_ski', 'week_start' => '2027-01-18'];

    return [
        'blue' => $base + ['code' => 'BLUE'],
        'red' => ($overrides['red'] ?? []) + $base + ['code' => 'RED'],
    ];
}

test('a swap between two full groups is accepted because the capacity is checked on the whole batch', function (): void {
    $occupancy = (new LevelReassignmentService)->validate(skGroups(), [
        ['enrollment_id' => 'e1', 'from_class_id' => 'blue', 'to_class_id' => 'red'],
        ['enrollment_id' => 'e2', 'from_class_id' => 'red', 'to_class_id' => 'blue'],
    ]);

    expect($occupancy)->toBe(['blue' => 2, 'red' => 2]);
});

test('a move into a full group is refused', function (): void {
    expect(skErrorCode(fn () => (new LevelReassignmentService)->validate(skGroups(), [['enrollment_id' => 'e1', 'from_class_id' => 'blue', 'to_class_id' => 'red']])))->toBe('capacity_exceeded');
});

test('moves between groups of another discipline or week, to a closed group, to the same group or twice are refused', function (): void {
    $service = new LevelReassignmentService;
    $move = [['enrollment_id' => 'e1', 'from_class_id' => 'blue', 'to_class_id' => 'red']];

    expect(skErrorCode(fn () => $service->validate(skGroups(['red' => ['discipline' => 'snowboard', 'enrolled' => 0]]), $move)))->toBe('group_mismatch')
        ->and(skErrorCode(fn () => $service->validate(skGroups(['red' => ['week_start' => '2027-01-25', 'enrolled' => 0]]), $move)))->toBe('group_mismatch')
        ->and(skErrorCode(fn () => $service->validate(skGroups(['red' => ['accepts' => false, 'enrolled' => 0]]), $move)))->toBe('group_closed')
        ->and(skErrorCode(fn () => $service->validate(skGroups(), [['enrollment_id' => 'e1', 'from_class_id' => 'blue', 'to_class_id' => 'blue']])))->toBe('same_group')
        ->and(skErrorCode(fn () => $service->validate(skGroups(['red' => ['enrolled' => 0]]), [$move[0], $move[0]])))->toBe('duplicate_move')
        ->and(skErrorCode(fn () => $service->validate(skGroups(), [['enrollment_id' => 'e1', 'from_class_id' => 'blue', 'to_class_id' => 'ghost']])))->toBe('group_not_found');
});

// --- Enumerations --------------------------------------------------------------------------------------------------------------------------

test('the disciplines cover the snow sports, the alpine guide and the outdoor activities', function (): void {
    expect(Discipline::values())->toBe(['alpine_ski', 'snowboard', 'telemark', 'cross_country', 'freeride', 'alpine_guide', 'outdoor_rafting', 'outdoor_canyoning', 'outdoor_paragliding', 'outdoor_mtb'])
        ->and(Discipline::SNOWBOARD->isSnowSport())->toBeTrue()->and(Discipline::OUTDOOR_RAFTING->isSnowSport())->toBeFalse();
});

test('only requested, confirmed and completed lessons occupy an instructor, and only the first two can still be changed', function (): void {
    expect(LessonStatus::CANCELLED->occupiesInstructor())->toBeFalse()->and(LessonStatus::NO_SHOW->occupiesInstructor())->toBeFalse()
        ->and(LessonStatus::COMPLETED->occupiesInstructor())->toBeTrue()->and(LessonStatus::COMPLETED->isOpen())->toBeFalse()
        ->and(LessonStatus::REQUESTED->isOpen())->toBeTrue()
        ->and(PackageItemType::ARTVA_KIT->isEquipment())->toBeTrue()->and(PackageItemType::SKIPASS->isEquipment())->toBeFalse();
});
