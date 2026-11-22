<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ResourcePlanningTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    rpDeferForeignKeys();
});

/**
 * The heatmap row of an employee, by name.
 *
 * @return array<string, mixed>
 */
function rpHeatmapRow(object $test, string $name, string $from, string $to, string $query = ''): array
{
    $data = $test->getJson("/api/v1/resource-planning/capacity/heatmap?from={$from}&to={$to}{$query}")->assertOk()->json('data');

    return collect($data['rows'])->firstWhere('employee_name', $name) ?? [];
}

it('deducts the national holiday of a canton and the approved leave from the contractual hours', function (): void {
    $tenant = rpTenant('CH');
    rpActAs($tenant, rpUser($tenant));
    $employee = rpEmployee($tenant, 'Anna Rossi');

    $cell = rpHeatmapRow($this, 'Anna Rossi', RP_ASCENSION_WEEK, '2027-05-09')['cells'][0];
    expect($cell['period_start'])->toBe(RP_ASCENSION_WEEK)
        ->and($cell['contractual_hours'])->toBe(40.0)
        ->and($cell['leave_and_holiday_hours'])->toBe(8.0)
        ->and($cell['net_available_hours'])->toBe(32.0);

    rpLeave($tenant, $employee, '2027-05-07', '2027-05-07');
    $cell = rpHeatmapRow($this, 'Anna Rossi', RP_ASCENSION_WEEK, '2027-05-09')['cells'][0];
    expect($cell['leave_and_holiday_hours'])->toBe(16.0)->and($cell['net_available_hours'])->toBe(24.0);

    rpLeave($tenant, $employee, '2027-05-03', '2027-05-03', 'pending_approval');
    rpLeave($tenant, $employee, '2027-05-04', '2027-05-04', 'rejected');
    $cell = rpHeatmapRow($this, 'Anna Rossi', RP_ASCENSION_WEEK, '2027-05-09')['cells'][0];
    expect($cell['net_available_hours'])->toBe(24.0);
});

it('applies the cantonal holidays of the working location of each employee', function (): void {
    $tenant = rpTenant('CH');
    rpActAs($tenant, rpUser($tenant));
    rpEmployee($tenant, 'Anna Ticino');
    rpEmployee($tenant, 'Bruno Zurich', metadata: ['work_location' => ['country' => 'CH', 'region' => 'ZH']]);

    // Tuesday 2027-06-29 (Saints Peter and Paul) is a holiday in Ticino only.
    expect(rpHeatmapRow($this, 'Anna Ticino', '2027-06-28', '2027-07-04')['cells'][0]['net_available_hours'])->toBe(32.0)
        ->and(rpHeatmapRow($this, 'Bruno Zurich', '2027-06-28', '2027-07-04')['cells'][0]['net_available_hours'])->toBe(40.0);
});

it('applies the Italian regional holidays and the patron saint of the municipality', function (): void {
    $tenant = rpTenant('IT');
    rpActAs($tenant, rpUser($tenant));
    rpEmployee($tenant, 'Carla Milano', metadata: ['work_location' => ['country' => 'IT', 'region' => 'MI', 'municipality' => 'Milano']]);
    rpEmployee($tenant, 'Dario Roma', metadata: ['work_location' => ['country' => 'IT', 'region' => 'RM', 'municipality' => 'Roma']]);
    rpEmployee($tenant, 'Elena Altrove');

    // Tuesday 2027-12-07 is Sant'Ambrogio in Milan, Wednesday 2027-12-08 the Immaculate Conception everywhere in Italy.
    expect(rpHeatmapRow($this, 'Carla Milano', '2027-12-06', '2027-12-12')['cells'][0]['net_available_hours'])->toBe(24.0)
        ->and(rpHeatmapRow($this, 'Dario Roma', '2027-12-06', '2027-12-12')['cells'][0]['net_available_hours'])->toBe(32.0)
        ->and(rpHeatmapRow($this, 'Elena Altrove', '2027-12-06', '2027-12-12')['cells'][0]['net_available_hours'])->toBe(32.0);
});

it('takes the patron saint of a municipality the tenant records itself', function (): void {
    $tenant = rpTenant('IT');
    rpActAs($tenant, rpUser($tenant));
    rpEmployee($tenant, 'Fabio Luino', metadata: ['work_location' => ['country' => 'IT', 'region' => 'VA', 'municipality' => 'Luino']]);

    expect(rpHeatmapRow($this, 'Fabio Luino', '2027-09-20', '2027-09-26')['cells'][0]['net_available_hours'])->toBe(40.0);

    $this->postJson('/api/v1/resource-planning/local-holidays', ['country' => 'it', 'region_code' => 'va', 'municipality' => 'Luino', 'month' => 9, 'day' => 23, 'name' => 'Local patron saint'])
        ->assertCreated()
        ->assertJsonPath('data.country', 'IT');

    expect(rpHeatmapRow($this, 'Fabio Luino', '2027-09-20', '2027-09-26')['cells'][0]['net_available_hours'])->toBe(32.0);

    $holidays = collect($this->getJson('/api/v1/resource-planning/holidays?country=IT&region=VA&municipality=Luino&year=2027')->assertOk()->json('data'));
    expect($holidays->pluck('date')->all())->toContain('2027-09-23');
});

it('scales the contractual week by the FTE of a full-time contract and keeps a part-time contract as stated', function (): void {
    $tenant = rpTenant('CH');
    rpActAs($tenant, rpUser($tenant));
    rpEmployee($tenant, 'Gina Half', weeklyHours: 40, ftePercentage: 50);
    rpEmployee($tenant, 'Hugo Stated', weeklyHours: 20, ftePercentage: 50);

    expect(rpHeatmapRow($this, 'Gina Half', RP_CLEAN_WEEK, '2027-02-14')['cells'][0]['net_available_hours'])->toBe(20.0)
        ->and(rpHeatmapRow($this, 'Hugo Stated', RP_CLEAN_WEEK, '2027-02-14')['cells'][0]['net_available_hours'])->toBe(20.0);
});

it('spreads a part-time week over the weekdays worked and ignores a holiday on a day off', function (): void {
    $tenant = rpTenant('CH');
    rpActAs($tenant, rpUser($tenant));
    $employee = rpEmployee($tenant, 'Ines Parttime', weeklyHours: 24);
    DB::table('hr_employment_contracts')->where('employee_id', $employee)->update(['terms' => json_encode(['work_weekdays' => [1, 2, 3]])]);

    // Ascension (Thursday) falls on a day off: nothing to deduct.
    $cell = rpHeatmapRow($this, 'Ines Parttime', RP_ASCENSION_WEEK, '2027-05-09')['cells'][0];
    expect($cell['contractual_hours'])->toBe(24.0)->and($cell['leave_and_holiday_hours'])->toBe(0.0)->and($cell['net_available_hours'])->toBe(24.0);
});

it('takes an hourly permission off the net capacity of its day', function (): void {
    $tenant = rpTenant('CH');
    rpActAs($tenant, rpUser($tenant));
    $employee = rpEmployee($tenant, 'Jana Hours');
    rpLeave($tenant, $employee, '2027-02-08', '2027-02-08', totalDays: 0.5, totalHours: 4);

    $cell = rpHeatmapRow($this, 'Jana Hours', RP_CLEAN_WEEK, '2027-02-14')['cells'][0];
    expect($cell['leave_and_holiday_hours'])->toBe(4.0)->and($cell['net_available_hours'])->toBe(36.0);
});

it('colours the load: green below 80 percent, yellow up to 100, red above, and flags the overload', function (): void {
    $tenant = rpTenant('CH');
    rpActAs($tenant, rpUser($tenant));
    $employee = rpEmployee($tenant, 'Karl Load');

    $band = fn (): array => rpHeatmapRow($this, 'Karl Load', RP_CLEAN_WEEK, '2027-02-14')['cells'][0];
    $book = fn (float $hours): string => rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', $hours, ['crm_opportunity_id' => rpOpportunity($tenant)])->assertCreated()->json('data.allocation.id');

    expect($band()['band'])->toBe('green')->and($band()['load_percent'])->toBe(0.0);

    $first = $book(3);
    expect($band()['band'])->toBe('green')->and($band()['load_percent'])->toBe(37.5)->and($band()['is_overloaded'])->toBeFalse();

    $this->deleteJson("/api/v1/resource-planning/allocations/{$first}")->assertNoContent();
    $second = $book(7);
    expect($band()['band'])->toBe('yellow')->and($band()['load_percent'])->toBe(87.5);

    $this->deleteJson("/api/v1/resource-planning/allocations/{$second}")->assertNoContent();
    $book(8);
    expect($band()['band'])->toBe('yellow')->and($band()['load_percent'])->toBe(100.0)->and($band()['is_overloaded'])->toBeFalse();

    $book(1);
    expect($band()['band'])->toBe('red')->and($band()['load_percent'])->toBe(112.5)->and($band()['is_overloaded'])->toBeTrue()
        ->and($band()['soft_booked_hours'])->toBe(45.0)->and($band()['hard_booked_hours'])->toBe(0.0);
});

it('shows a booking on a week without net capacity as an overload', function (): void {
    $tenant = rpTenant('CH');
    rpActAs($tenant, rpUser($tenant));
    $employee = rpEmployee($tenant, 'Lena Leave');
    rpLeave($tenant, $employee, '2027-02-08', '2027-02-12');
    rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 4, ['crm_opportunity_id' => rpOpportunity($tenant)])->assertCreated();

    $cell = rpHeatmapRow($this, 'Lena Leave', RP_CLEAN_WEEK, '2027-02-14')['cells'][0];
    expect($cell['net_available_hours'])->toBe(0.0)->and($cell['load_percent'])->toBe(999.0)->and($cell['band'])->toBe('red')->and($cell['is_overloaded'])->toBeTrue();
});

it('lists the days of a week with a holiday as a day without capacity', function (): void {
    $tenant = rpTenant('CH');
    rpActAs($tenant, rpUser($tenant));
    rpEmployee($tenant, 'Marco Daily');

    $data = $this->getJson('/api/v1/resource-planning/capacity/heatmap?granularity=day&from=2027-05-03&to=2027-05-09')->assertOk()->json('data');
    $cells = collect($data['rows'][0]['cells'])->keyBy('period_start');

    expect($data['granularity'])->toBe('day')->and($data['periods'])->toHaveCount(7)
        ->and($data['thresholds'])->toBe(['yellow' => 80.0, 'red' => 100.0])
        ->and($cells['2027-05-06']['net_available_hours'])->toBe(0.0)
        ->and($cells['2027-05-06']['band'])->toBe('none')
        ->and($cells['2027-05-05']['net_available_hours'])->toBe(8.0)
        ->and($cells['2027-05-08']['contractual_hours'])->toBe(0.0);
});

it('filters the heatmap by department and by employee', function (): void {
    $tenant = rpTenant('CH');
    rpActAs($tenant, rpUser($tenant));
    $design = rpDepartment($tenant, 'Design');
    $build = rpDepartment($tenant, 'Build');
    $anna = rpEmployee($tenant, 'Anna Design', departmentId: $design);
    rpEmployee($tenant, 'Bruno Build', departmentId: $build);

    $rows = $this->getJson("/api/v1/resource-planning/capacity/heatmap?from=2027-02-08&to=2027-02-14&department_id={$design}")->assertOk()->json('data.rows');
    expect($rows)->toHaveCount(1)->and($rows[0]['department_name'])->toBe('Design');

    $rows = $this->getJson("/api/v1/resource-planning/capacity/heatmap?from=2027-02-08&to=2027-02-14&employee_id[]={$anna}")->assertOk()->json('data.rows');
    expect($rows)->toHaveCount(1)->and($rows[0]['employee_name'])->toBe('Anna Design');
});

it('lists the planable employees with their contract data', function (): void {
    $tenant = rpTenant('CH');
    rpActAs($tenant, rpUser($tenant));
    rpEmployee($tenant, 'Nora Resource', weeklyHours: 40, ftePercentage: 80, hourlyCost: 65);

    $resource = $this->getJson('/api/v1/resource-planning/resources')->assertOk()->json('data.0');

    expect($resource['full_name'])->toBe('Nora Resource')
        ->and($resource['weekly_contract_hours'])->toBe(32.0)
        ->and($resource['fte_ratio'])->toBe(0.8)
        ->and($resource['hourly_cost'])->toBe(65.0)
        ->and($resource['country'])->toBe('CH')
        ->and($resource['region'])->toBe('TI');
});
