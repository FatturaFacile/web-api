<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\ShiftPlanning\Domain\Enums\Jurisdiction;
use Modules\ShiftPlanning\Domain\Enums\PeriodType;
use Modules\ShiftPlanning\Domain\Enums\RosterStatus;
use Modules\ShiftPlanning\Domain\Enums\RotationPattern;
use Modules\ShiftPlanning\Domain\Enums\SwapStatus;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftConflictException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftPlanningException;
use Modules\ShiftPlanning\Domain\Services\ChLaborComplianceStrategy;
use Modules\ShiftPlanning\Domain\Services\ItLaborComplianceStrategy;
use Modules\ShiftPlanning\Domain\Services\LaborComplianceStrategyResolver;
use Modules\ShiftPlanning\Domain\Services\PublicHolidayCalendar;
use Modules\ShiftPlanning\Domain\Services\RotationPlanner;
use Modules\ShiftPlanning\Domain\ValueObjects\ComplianceIssue;
use Modules\ShiftPlanning\Domain\ValueObjects\SurchargeBreakdown;
use Modules\ShiftPlanning\Infrastructure\Models\RosterSchedulePeriod;
use Modules\ShiftPlanning\Infrastructure\Models\ShiftSwapRequest;
use Modules\ShiftPlanning\Infrastructure\Models\ShiftTemplate;

it('resolves the strategy of the jurisdiction from the container', function (): void {
    $resolver = app(LaborComplianceStrategyResolver::class);

    expect($resolver->for(Jurisdiction::Switzerland))->toBeInstanceOf(ChLaborComplianceStrategy::class)
        ->and($resolver->for(Jurisdiction::Italy))->toBeInstanceOf(ItLaborComplianceStrategy::class)
        ->and(Jurisdiction::fromCountry('it'))->toBe(Jurisdiction::Italy)
        ->and(Jurisdiction::fromCountry('FR'))->toBe(Jurisdiction::Switzerland);
});

it('lets a collective agreement replace the strategy of a jurisdiction', function (): void {
    $stricter = new class extends ItLaborComplianceStrategy {};
    $resolver = new LaborComplianceStrategyResolver([new ItLaborComplianceStrategy, $stricter, new ChLaborComplianceStrategy]);

    expect($resolver->for(Jurisdiction::Italy))->toBe($stricter);
});

it('computes Easter and the public holidays of the two jurisdictions', function (): void {
    $calendar = new PublicHolidayCalendar;

    expect(PublicHolidayCalendar::easterSunday(2026)->format('Y-m-d'))->toBe('2026-04-05')
        ->and(PublicHolidayCalendar::easterSunday(2027)->format('Y-m-d'))->toBe('2027-03-28')
        ->and($calendar->holidays(Jurisdiction::Italy, 2026))->toContain('2026-04-06', '2026-04-25', '2026-08-15')
        ->and($calendar->holidays(Jurisdiction::Switzerland, 2026))->toContain('2026-04-03', '2026-04-06', '2026-05-14', '2026-05-25', '2026-08-01')
        ->and($calendar->isHoliday(Jurisdiction::Switzerland, CarbonImmutable::parse('2026-04-25')))->toBeFalse()
        ->and($calendar->isHoliday(Jurisdiction::Italy, CarbonImmutable::parse('2026-04-25')))->toBeTrue();
});

it('lays a 5x2 pattern over a week with the weekend off and rotating templates', function (): void {
    $plan = (new RotationPlanner)->plan(RotationPattern::FiveByTwo, CarbonImmutable::parse('2026-03-02'), CarbonImmutable::parse('2026-03-15'), 2, 3);

    $first = array_values(array_filter($plan, static fn (array $slot): bool => $slot['employee_index'] === 0));

    expect($plan)->toHaveCount(20)
        ->and(array_unique(array_map(static fn (array $slot): int => $slot['date']->isoWeekday(), $plan)))->toEqualCanonicalizing([1, 2, 3, 4, 5])
        ->and(array_values(array_unique(array_column(array_slice($first, 0, 5), 'template_index'))))->toBe([0])
        ->and(array_values(array_unique(array_column(array_slice($first, 5, 5), 'template_index'))))->toBe([1]);
});

it('lays a 6x6 pattern with the two halves of the team six days apart', function (): void {
    $plan = (new RotationPlanner)->plan(RotationPattern::SixBySix, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-24'), 2, 2);
    $days = static fn (int $employee): array => array_values(array_map(
        static fn (array $slot): int => (int) CarbonImmutable::parse('2026-03-01')->diffInDays($slot['date']),
        array_filter($plan, static fn (array $slot): bool => $slot['employee_index'] === $employee),
    ));

    expect($days(0))->toBe([0, 1, 2, 3, 4, 5, 12, 13, 14, 15, 16, 17])
        ->and($days(1))->toBe([6, 7, 8, 9, 10, 11, 18, 19, 20, 21, 22, 23])
        ->and((new RotationPlanner)->plan(RotationPattern::SixBySix, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-02'), 0, 2))->toBe([]);
});

it('validates the integrity of weekly and monthly periods', function (): void {
    $period = static fn (PeriodType $type, string $from, string $to): RosterSchedulePeriod => new RosterSchedulePeriod(['period_type' => $type, 'start_date' => $from, 'end_date' => $to]);

    $period(PeriodType::Weekly, '2026-03-02', '2026-03-08')->assertIntegrity();
    $period(PeriodType::Monthly, '2026-02-01', '2026-02-28')->assertIntegrity();

    expect(fn () => $period(PeriodType::Weekly, '2026-03-02', '2026-03-09')->assertIntegrity())->toThrow(ShiftPlanningException::class)
        ->and(fn () => $period(PeriodType::Monthly, '2026-02-02', '2026-02-28')->assertIntegrity())->toThrow(ShiftPlanningException::class)
        ->and(fn () => $period(PeriodType::Monthly, '2026-02-01', '2026-02-27')->assertIntegrity())->toThrow(ShiftPlanningException::class);
});

it('moves a period from draft to published to locked and never back', function (): void {
    $period = new RosterSchedulePeriod(['period_type' => PeriodType::Weekly, 'start_date' => '2026-03-02', 'end_date' => '2026-03-08', 'status' => RosterStatus::Draft]);

    expect(fn () => $period->lock('user'))->toThrow(ShiftPlanningException::class)
        ->and(RosterStatus::Draft->isEditable())->toBeTrue()
        ->and(RosterStatus::Published->isVisibleToEmployees())->toBeTrue()
        ->and(RosterStatus::Draft->isVisibleToEmployees())->toBeFalse()
        ->and(RosterStatus::Locked->isEditable())->toBeFalse();
});

it('moves a swap through its two approval steps only in order', function (): void {
    $swap = new ShiftSwapRequest(['requester_employee_id' => 'a', 'target_peer_employee_id' => 'b', 'status' => SwapStatus::PendingPeerAcceptance]);

    expect(fn () => $swap->approve('manager'))->toThrow(ShiftConflictException::class)
        ->and(fn () => $swap->acceptByPeer('a'))->toThrow(ShiftPlanningException::class)
        ->and(fn () => $swap->acceptByPeer('c'))->toThrow(ShiftPlanningException::class)
        ->and(SwapStatus::PendingManagerApproval->isOpen())->toBeTrue()
        ->and(SwapStatus::Approved->isOpen())->toBeFalse();
});

it('derives the instants of a night template across midnight in the local time zone', function (): void {
    $night = new ShiftTemplate(['start_time' => '22:00:00', 'end_time' => '06:00:00']);
    $day = new ShiftTemplate(['start_time' => '08:00:00', 'end_time' => '16:30:00']);
    $date = CarbonImmutable::parse('2026-07-06');

    [$nightStart, $nightEnd] = $night->instantsOn($date, Jurisdiction::Switzerland);
    [$dayStart, $dayEnd] = $day->instantsOn($date, Jurisdiction::Italy);

    expect($nightStart->format('Y-m-d H:i'))->toBe('2026-07-06 20:00')
        ->and($nightEnd->format('Y-m-d H:i'))->toBe('2026-07-07 04:00')
        ->and($dayStart->format('H:i'))->toBe('06:00')
        ->and($dayEnd->format('H:i'))->toBe('14:30');
});

it('round-trips a compliance issue and a surcharge breakdown through their JSON form', function (): void {
    $issue = ComplianceIssue::blocking('IT.DAILY_REST_11H', ['rest_minutes' => 480]);
    $breakdown = new SurchargeBreakdown(480, 420, 0, 0, 0, 25.0, 0);

    expect(ComplianceIssue::fromArray($issue->toArray()))->toEqual($issue)
        ->and($issue->translationKey)->toBe('SHIFT_PLANNING.RULE_IT_DAILY_REST_11H')
        ->and(SurchargeBreakdown::fromArray($breakdown->toArray()))->toEqual($breakdown)
        ->and($breakdown->tags())->toBe(['night'])
        ->and(SurchargeBreakdown::fromArray(null)->workedMinutes)->toBe(0);
});
