<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/ResourcePlanningTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    rpDeferForeignKeys();
});

/**
 * Anna (Design) is committed 6 hours a day on a billable project and held 2 hours a day, tentatively, for an opportunity;
 * Bruno (Build) works 4 hours a day on a non-billable project. The week is the clean one of the calendar anchors (40 hours each).
 *
 * @return array<string, string>
 */
function rpUtilizationSetup(object $test): array
{
    $tenant = rpTenant('CH');
    rpActAs($tenant, rpUser($tenant));
    $design = rpDepartment($tenant, 'Design');
    $build = rpDepartment($tenant, 'Build');
    $anna = rpEmployee($tenant, 'Anna Rossi', departmentId: $design);
    $bruno = rpEmployee($tenant, 'Bruno Bianchi', departmentId: $build);

    rpAllocate($test, $anna, RP_CLEAN_WEEK, '2027-02-12', 6, ['booking_type' => 'HardCommitted', 'project_id' => rpProject($tenant)])->assertCreated();
    rpAllocate($test, $anna, RP_CLEAN_WEEK, '2027-02-12', 2, ['crm_opportunity_id' => rpOpportunity($tenant)])->assertCreated();
    rpAllocate($test, $bruno, RP_CLEAN_WEEK, '2027-02-12', 4, ['booking_type' => 'HardCommitted', 'project_id' => rpProject($tenant), 'is_billable' => false])->assertCreated();

    return ['design' => $design, 'build' => $build, 'anna' => $anna, 'bruno' => $bruno, 'tenant' => (string) $tenant->getKey()];
}

it('reports the committed and forecast billable utilization of each resource against the default target', function (): void {
    rpUtilizationSetup($this);

    $report = $this->getJson('/api/v1/resource-planning/reports/billable-utilization?from=2027-02-08&to=2027-02-14&group_by=resource')->assertOk()->json('data');
    $rows = collect($report['rows'])->keyBy('key_name');

    expect($report['group_by'])->toBe('resource')
        ->and($report['default_target_percent'])->toEqual(75.0)
        ->and($rows['Anna Rossi']['net_available_hours'])->toEqual(40.0)
        ->and($rows['Anna Rossi']['billable_committed_hours'])->toEqual(30.0)
        ->and($rows['Anna Rossi']['tentative_booked_hours'])->toEqual(10.0)
        ->and($rows['Anna Rossi']['actual_billable_percent'])->toEqual(75.0)
        ->and($rows['Anna Rossi']['forecast_billable_percent'])->toEqual(100.0)
        ->and($rows['Anna Rossi']['target_percent'])->toEqual(75.0)
        ->and($rows['Anna Rossi']['gap_percent'])->toEqual(0.0)
        ->and($rows['Anna Rossi']['status'])->toBe('on_target')
        ->and($rows['Bruno Bianchi']['internal_booked_hours'])->toEqual(20.0)
        ->and($rows['Bruno Bianchi']['actual_billable_percent'])->toEqual(0.0)
        ->and($rows['Bruno Bianchi']['gap_percent'])->toEqual(-75.0)
        ->and($rows['Bruno Bianchi']['status'])->toBe('below_target')
        ->and($report['totals']['net_available_hours'])->toEqual(80.0)
        ->and($report['totals']['actual_billable_percent'])->toEqual(37.5);
});

it('groups the utilization by department with the target of each department', function (): void {
    $setup = rpUtilizationSetup($this);

    $this->putJson('/api/v1/resource-planning/utilization-targets', ['department_id' => $setup['design'], 'target_percent' => 85])
        ->assertOk()
        ->assertJsonPath('data.department_id', $setup['design'])
        ->assertJsonPath('data.target_percent', 85);

    $rows = collect($this->getJson('/api/v1/resource-planning/reports/billable-utilization?from=2027-02-08&to=2027-02-14&group_by=department')->assertOk()->json('data.rows'))->keyBy('key_name');

    expect($rows['Design']['target_percent'])->toEqual(85.0)
        ->and($rows['Design']['gap_percent'])->toEqual(-10.0)
        ->and($rows['Design']['status'])->toBe('below_target')
        ->and($rows['Build']['target_percent'])->toEqual(75.0)
        ->and($rows['Design']['key_id'])->toBe($setup['design']);
});

it('sets a tenant default target and updates a target instead of duplicating it', function (): void {
    $setup = rpUtilizationSetup($this);

    $this->putJson('/api/v1/resource-planning/utilization-targets', ['target_percent' => 60])->assertOk();
    $this->putJson('/api/v1/resource-planning/utilization-targets', ['target_percent' => 65])->assertOk();
    $this->putJson('/api/v1/resource-planning/utilization-targets', ['department_id' => $setup['build'], 'target_percent' => 50])->assertOk();

    $targets = $this->getJson('/api/v1/resource-planning/utilization-targets')->assertOk()->json('data');
    expect($targets)->toHaveCount(2);

    $report = $this->getJson('/api/v1/resource-planning/reports/billable-utilization?from=2027-02-08&to=2027-02-14')->assertOk()->json('data');
    $rows = collect($report['rows'])->keyBy('key_name');

    expect($report['default_target_percent'])->toEqual(65.0)
        ->and($rows['Anna Rossi']['target_percent'])->toEqual(65.0)
        ->and($rows['Bruno Bianchi']['target_percent'])->toEqual(50.0)
        ->and($rows['Anna Rossi']['status'])->toBe('above_target');
});

it('puts the employees without a cost centre in an unassigned group', function (): void {
    rpUtilizationSetup($this);

    $rows = $this->getJson('/api/v1/resource-planning/reports/billable-utilization?from=2027-02-08&to=2027-02-14&group_by=cost_center')->assertOk()->json('data.rows');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['key_name'])->toBe('unassigned')
        ->and($rows[0]['key_id'])->toBeNull()
        ->and($rows[0]['net_available_hours'])->toEqual(80.0);
});

it('refuses a target of a department and a cost centre at once, and a target above 100 percent', function (): void {
    $setup = rpUtilizationSetup($this);

    $this->putJson('/api/v1/resource-planning/utilization-targets', ['department_id' => $setup['design'], 'cost_center_id' => (string) Str::uuid(), 'target_percent' => 70])->assertUnprocessable();
    $this->putJson('/api/v1/resource-planning/utilization-targets', ['target_percent' => 101])->assertUnprocessable();
});

it('sums the weeks of a longer window and recomputes the snapshots it reads', function (): void {
    $setup = rpUtilizationSetup($this);

    // A second week without bookings halves the utilization of Anna.
    $report = $this->getJson('/api/v1/resource-planning/reports/billable-utilization?from=2027-02-08&to=2027-02-21&group_by=resource')->assertOk()->json('data');
    $anna = collect($report['rows'])->firstWhere('key_name', 'Anna Rossi');

    expect($report['from'])->toBe('2027-02-08')->and($report['to'])->toBe('2027-02-21')
        ->and($anna['net_available_hours'])->toEqual(80.0)
        ->and($anna['billable_committed_hours'])->toEqual(30.0)
        ->and($anna['actual_billable_percent'])->toEqual(37.5);

    // A booking made after the first read is picked up by the next one.
    rpAllocate($this, $setup['anna'], '2027-02-15', '2027-02-19', 6, ['booking_type' => 'HardCommitted', 'project_id' => rpProject(Tenant::query()->findOrFail($setup['tenant']))])->assertCreated();
    $anna = collect($this->getJson('/api/v1/resource-planning/reports/billable-utilization?from=2027-02-08&to=2027-02-21')->json('data.rows'))->firstWhere('key_name', 'Anna Rossi');
    expect($anna['billable_committed_hours'])->toEqual(60.0)->and($anna['actual_billable_percent'])->toEqual(75.0);
});
