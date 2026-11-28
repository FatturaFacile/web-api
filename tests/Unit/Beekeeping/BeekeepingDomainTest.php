<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Beekeeping\Domain\ValueObjects\GeoPoint;
use Modules\Beekeeping\Domain\ValueObjects\HoneyQuality;
use Modules\Beekeeping\Domain\ValueObjects\QueenMarking;
use Modules\Beekeeping\Domain\ValueObjects\WithdrawalWindow;
use Modules\Beekeeping\Services\ColonyAlertService;

it('colours the queen by the last digit of the year: 1/6 white, 2/7 yellow, 3/8 red, 4/9 green, 5/0 blue', function (int $year, string $color): void {
    expect(QueenMarking::colorForYear($year))->toBe($color);
})->with([
    [2021, 'white'], [2026, 'white'], [2022, 'yellow'], [2027, 'yellow'], [2023, 'red'], [2028, 'red'], [2024, 'green'], [2029, 'green'], [2025, 'blue'], [2030, 'blue'],
]);

it('ages a queen in whole years and never below zero', function (): void {
    expect(QueenMarking::ageInYears(2023, 2026))->toBe(3)->and(QueenMarking::ageInYears(2027, 2026))->toBe(0);
});

it('measures the distance between two apiaries with the haversine formula', function (): void {
    $milan = new GeoPoint(45.4642, 9.1900);
    $bergamo = new GeoPoint(45.6983, 9.6773);

    expect($milan->distanceTo($bergamo))->toBeGreaterThan(44000)->toBeLessThan(46000)->and($milan->distanceTo($milan))->toBe(0.0);
});

it('overlaps foraging circles when the distance is below the sum of the radii', function (): void {
    $a = new GeoPoint(45.7000, 9.6700, 3000);
    $near = new GeoPoint(45.7100, 9.6800, 3000);
    $far = new GeoPoint(46.5000, 9.9000, 3000);

    expect($a->overlaps($near))->toBeTrue()->and($a->overlaps($far))->toBeFalse()->and($a->overlapPercent($far))->toBe(0.0)->and($a->overlapPercent($near))->toBeGreaterThan(50.0);
    expect($a->overlapPercent($a))->toBe(100.0);
});

it('rejects coordinates outside the WGS84 range', function (): void {
    new GeoPoint(91.0, 0.0);
})->throws(InvalidArgumentException::class);

it('covers the days from the treatment date up to but excluding the end of the withdrawal', function (): void {
    $window = new WithdrawalWindow(CarbonImmutable::parse('2026-06-10'), 30);

    expect($window->end->toDateString())->toBe('2026-07-10')
        ->and($window->covers(CarbonImmutable::parse('2026-06-09')))->toBeFalse()
        ->and($window->covers(CarbonImmutable::parse('2026-06-10')))->toBeTrue()
        ->and($window->covers(CarbonImmutable::parse('2026-07-09')))->toBeTrue()
        ->and($window->covers(CarbonImmutable::parse('2026-07-10')))->toBeFalse()
        ->and($window->daysLeft(CarbonImmutable::parse('2026-06-15')))->toBe(25)
        ->and($window->daysLeft(CarbonImmutable::parse('2026-08-01')))->toBe(0);
});

it('covers nothing for a withdrawal of zero days', function (): void {
    $window = new WithdrawalWindow(CarbonImmutable::parse('2026-06-10'), 0);

    expect($window->covers(CarbonImmutable::parse('2026-06-10')))->toBeFalse();
});

it('rejects a negative withdrawal', function (): void {
    new WithdrawalWindow(CarbonImmutable::parse('2026-06-10'), -1);
})->throws(InvalidArgumentException::class);

it('grades the moisture and the HMF of a honey against the statutory limits', function (?float $moisture, ?float $hmf, string $moistureStatus, string $hmfStatus, bool $marketable): void {
    $quality = new HoneyQuality($moisture, $hmf);

    expect($quality->moistureStatus())->toBe($moistureStatus)->and($quality->hmfStatus())->toBe($hmfStatus)->and($quality->isMarketable())->toBe($marketable);
})->with([
    'unknown' => [null, null, 'unknown', 'unknown', true],
    'good' => [17.0, 10.0, 'ok', 'ok', true],
    'moisture at 18 is still fine' => [18.0, 29.9, 'ok', 'ok', true],
    'moisture above 18' => [18.1, 30.0, 'warning', 'warning', true],
    'moisture at the 20 limit' => [20.0, 40.0, 'warning', 'warning', true],
    'moisture above 20' => [20.1, 10.0, 'critical', 'ok', false],
    'HMF above 40' => [16.0, 40.1, 'ok', 'critical', false],
]);

it('lists the warning codes of a honey batch', function (): void {
    expect((new HoneyQuality(19.0, 35.0))->warnings())->toBe(['moisture_warning', 'hmf_warning'])
        ->and((new HoneyQuality(21.0, 45.0))->warnings())->toBe(['moisture_critical', 'hmf_critical'])
        ->and((new HoneyQuality(16.0, 5.0))->warnings())->toBe([]);
});

it('raises the colony alerts the beekeeper must act on', function (array $facts, array $expected): void {
    $alerts = (new ColonyAlertService)->alerts($facts + ['brood_frames' => 4, 'supers_count' => 1, 'strength_score' => 3], 2026);

    expect($alerts)->toBe($expected);
})->with([
    'healthy' => [['queen_seen' => true, 'eggs_present' => true], []],
    'swarm cells left' => [['swarm_cells_found' => 2, 'swarm_cells_destroyed' => 1], ['swarming_risk']],
    'swarm cells destroyed' => [['swarm_cells_found' => 2, 'swarm_cells_destroyed' => 2], []],
    'crowded without supers' => [['brood_frames' => 8, 'supers_count' => 0], ['swarming_risk']],
    'crowded with supers' => [['brood_frames' => 8, 'supers_count' => 2], []],
    'no queen, eggs or larvae' => [['queen_seen' => false, 'eggs_present' => false, 'larvae_present' => false], ['queenless']],
    'queen marked missing' => [['queen_status' => 'missing'], ['queenless']],
    'weak colony' => [['strength_score' => 2], ['weak_colony']],
    'old queen' => [['queen_year' => 2023], ['old_queen']],
    'young queen' => [['queen_year' => 2025], []],
]);
