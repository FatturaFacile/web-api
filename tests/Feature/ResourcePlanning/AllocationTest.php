<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/ResourcePlanningTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    rpDeferForeignKeys();
});

/**
 * A Swiss tenant (Ticino) with an owner, one employee on a 40 hour week and the clean week of the calendar anchors.
 *
 * @return array{0: Tenant, 1: string}
 */
function rpPlanner(object $test): array
{
    $tenant = rpTenant('CH');
    rpActAs($tenant, rpUser($tenant));

    return [$tenant, rpEmployee($tenant, 'Anna Rossi')];
}

it('holds capacity for an open opportunity with a tentative booking', function (): void {
    [$tenant, $employee] = rpPlanner($this);
    $opportunity = rpOpportunity($tenant, 'open', 'Warehouse automation');

    $response = rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 4, ['crm_opportunity_id' => $opportunity])->assertCreated();

    $response->assertJsonPath('data.allocation.booking_type', 'SoftTentative')
        ->assertJsonPath('data.allocation.total_allocated_hours', 20)
        ->assertJsonPath('data.allocation.crm_opportunity_name', 'Warehouse automation')
        ->assertJsonPath('data.allocation.employee_name', 'Anna Rossi')
        ->assertJsonPath('data.allocation.is_overloaded', false)
        ->assertJsonPath('data.overload.is_overloaded', false);
});

it('refuses a tentative booking for a closed opportunity and one without any reference', function (): void {
    [$tenant, $employee] = rpPlanner($this);

    rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 4, ['crm_opportunity_id' => rpOpportunity($tenant, 'lost')])
        ->assertUnprocessable()
        ->assertJsonPath('invalid_params.0.code', 'opportunity_not_open');

    rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 4)
        ->assertUnprocessable()
        ->assertJsonPath('invalid_params.0.code', 'soft_booking_requires_reference');
});

it('commits an employee to an active project and a WBS node, and refuses what Mod 078 does not allow', function (): void {
    [$tenant, $employee] = rpPlanner($this);
    $project = rpProject($tenant, 'active');
    $node = rpWbsNode($tenant, $project);

    rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 6, ['booking_type' => 'HardCommitted', 'project_id' => $project, 'wbs_node_id' => $node])
        ->assertCreated()
        ->assertJsonPath('data.allocation.project_name', 'Plant retrofit')
        ->assertJsonPath('data.allocation.wbs_node_title', 'Commissioning')
        ->assertJsonPath('data.allocation.total_allocated_hours', 30);

    rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 1, ['booking_type' => 'HardCommitted', 'project_id' => rpProject($tenant, 'draft')])
        ->assertUnprocessable()
        ->assertJsonPath('invalid_params.0.code', 'project_not_allocatable');

    rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 1, ['booking_type' => 'HardCommitted', 'project_id' => rpProject($tenant, 'active'), 'wbs_node_id' => $node])
        ->assertUnprocessable()
        ->assertJsonPath('invalid_params.0.code', 'wbs_node_not_in_project');

    rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 1, ['booking_type' => 'HardCommitted'])->assertUnprocessable();
});

it('counts only the days the employee works: no weekend and no holiday', function (): void {
    [$tenant, $employee] = rpPlanner($this);

    // Monday to Sunday of the Ascension week: Thursday is a public holiday, so Monday, Tuesday, Wednesday and Friday count.
    rpAllocate($this, $employee, RP_ASCENSION_WEEK, '2027-05-09', 6, ['crm_opportunity_id' => rpOpportunity($tenant)])
        ->assertCreated()
        ->assertJsonPath('data.allocation.total_allocated_hours', 24);

    rpAllocate($this, $employee, '2027-05-08', '2027-05-09', 6, ['crm_opportunity_id' => rpOpportunity($tenant)])
        ->assertUnprocessable()
        ->assertJsonPath('invalid_params.0.code', 'no_working_days');
});

it('flags the overload above 100 percent of the net capacity, tentative bookings included', function (): void {
    [$tenant, $employee] = rpPlanner($this);
    $project = rpProject($tenant);

    $first = rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 6, ['booking_type' => 'HardCommitted', 'project_id' => $project])->assertCreated();
    $first->assertJsonPath('data.overload.is_overloaded', false);

    $second = rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 4, ['crm_opportunity_id' => rpOpportunity($tenant)])->assertCreated();
    $second->assertJsonPath('data.overload.is_overloaded', true)
        ->assertJsonPath('data.overload.peak_load_percent', 125)
        ->assertJsonPath('data.overload.weeks.0.week_start', RP_CLEAN_WEEK);

    $list = $this->getJson('/api/v1/resource-planning/allocations?from=2027-02-01&to=2027-02-28')->assertOk()->json('data');
    expect(collect($list)->pluck('is_overloaded')->all())->toBe([true, true]);
});

it('refuses a booking that would overload when overload is not allowed', function (): void {
    [$tenant, $employee] = rpPlanner($this);

    rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 6, ['crm_opportunity_id' => rpOpportunity($tenant)])->assertCreated();

    rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 4, ['crm_opportunity_id' => rpOpportunity($tenant), 'allow_overload' => false])
        ->assertUnprocessable()
        ->assertJsonPath('invalid_params.0.code', 'overload');

    expect($this->getJson('/api/v1/resource-planning/allocations')->json('data'))->toHaveCount(1);
});

it('converts a tentative booking into a committed one once it has a project', function (): void {
    [$tenant, $employee] = rpPlanner($this);
    $allocation = rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 4, ['crm_opportunity_id' => rpOpportunity($tenant)])->assertCreated()->json('data.allocation.id');

    $this->postJson("/api/v1/resource-planning/allocations/{$allocation}/convert-to-hard", [])
        ->assertUnprocessable()
        ->assertJsonPath('invalid_params.0.code', 'hard_booking_requires_project');

    $project = rpProject($tenant);
    $this->postJson("/api/v1/resource-planning/allocations/{$allocation}/convert-to-hard", ['project_id' => $project, 'wbs_node_id' => rpWbsNode($tenant, $project)])
        ->assertOk()
        ->assertJsonPath('data.allocation.booking_type', 'HardCommitted')
        ->assertJsonPath('data.allocation.project_id', $project)
        ->assertJsonPath('data.allocation.wbs_node_title', 'Commissioning');

    $this->postJson("/api/v1/resource-planning/allocations/{$allocation}/convert-to-hard", ['project_id' => $project])
        ->assertUnprocessable()
        ->assertJsonPath('invalid_params.0.code', 'already_hard_committed');
});

it('relieves an overload by shifting the allocation to the next week', function (): void {
    [$tenant, $employee] = rpPlanner($this);
    rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 6, ['crm_opportunity_id' => rpOpportunity($tenant)])->assertCreated();
    $second = rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 4, ['crm_opportunity_id' => rpOpportunity($tenant)])->assertCreated()->json('data.allocation.id');

    $this->postJson("/api/v1/resource-planning/allocations/{$second}/rebalance", ['strategy' => 'shift', 'shift_days' => 7])
        ->assertOk()
        ->assertJsonPath('data.allocation.start_date', '2027-02-15')
        ->assertJsonPath('data.allocation.end_date', '2027-02-19')
        ->assertJsonPath('data.overload.is_overloaded', false);

    $list = $this->getJson('/api/v1/resource-planning/allocations?from=2027-02-01&to=2027-02-28')->json('data');
    expect(collect($list)->pluck('is_overloaded')->unique()->all())->toBe([false]);
});

it('relieves an overload by handing the allocation to a colleague (drag onto another row)', function (): void {
    [$tenant, $employee] = rpPlanner($this);
    $colleague = rpEmployee($tenant, 'Bruno Bianchi');
    rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 6, ['crm_opportunity_id' => rpOpportunity($tenant)])->assertCreated();
    $second = rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 4, ['crm_opportunity_id' => rpOpportunity($tenant)])->assertCreated()->json('data.allocation.id');

    $this->postJson("/api/v1/resource-planning/allocations/{$second}/rebalance", ['strategy' => 'reassign', 'employee_id' => $colleague])
        ->assertOk()
        ->assertJsonPath('data.allocation.employee_name', 'Bruno Bianchi')
        ->assertJsonPath('data.overload.is_overloaded', false);

    $this->postJson("/api/v1/resource-planning/allocations/{$second}/rebalance", ['strategy' => 'shift', 'start_date' => '2027-02-15', 'employee_id' => $employee])
        ->assertOk()
        ->assertJsonPath('data.allocation.employee_name', 'Anna Rossi')
        ->assertJsonPath('data.allocation.start_date', '2027-02-15');
});

it('splits an allocation in two and can send the second part to a colleague', function (): void {
    [$tenant, $employee] = rpPlanner($this);
    $colleague = rpEmployee($tenant, 'Bruno Bianchi');
    $allocation = rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 8, ['crm_opportunity_id' => rpOpportunity($tenant)])->assertCreated()->json('data.allocation.id');

    $response = $this->postJson("/api/v1/resource-planning/allocations/{$allocation}/rebalance", ['strategy' => 'split', 'split_date' => '2027-02-10', 'employee_id' => $colleague])->assertOk();

    $parts = collect($response->json('data.allocations'));
    expect($parts)->toHaveCount(2)
        ->and($parts[0]['end_date'])->toBe('2027-02-09')
        ->and($parts[0]['employee_name'])->toBe('Anna Rossi')
        ->and($parts[0]['total_allocated_hours'])->toEqual(16.0)
        ->and($parts[1]['start_date'])->toBe('2027-02-10')
        ->and($parts[1]['end_date'])->toBe('2027-02-12')
        ->and($parts[1]['employee_name'])->toBe('Bruno Bianchi')
        ->and($parts[1]['total_allocated_hours'])->toEqual(24.0)
        ->and($parts[1]['crm_opportunity_id'])->toBe($parts[0]['crm_opportunity_id']);

    $this->postJson("/api/v1/resource-planning/allocations/{$parts[1]['id']}/rebalance", ['strategy' => 'split', 'split_date' => '2027-02-08'])
        ->assertUnprocessable()
        ->assertJsonPath('invalid_params.0.code', 'invalid_period');
});

it('resizes the dates and the hours of an allocation', function (): void {
    [$tenant, $employee] = rpPlanner($this);
    $allocation = rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 8, ['crm_opportunity_id' => rpOpportunity($tenant)])->assertCreated()->json('data.allocation.id');

    $this->postJson("/api/v1/resource-planning/allocations/{$allocation}/rebalance", ['strategy' => 'resize', 'end_date' => '2027-02-10', 'allocated_hours_per_day' => 4])
        ->assertOk()
        ->assertJsonPath('data.allocation.end_date', '2027-02-10')
        ->assertJsonPath('data.allocation.allocated_hours_per_day', 4)
        ->assertJsonPath('data.allocation.total_allocated_hours', 12);

    $this->postJson("/api/v1/resource-planning/allocations/{$allocation}/rebalance", ['strategy' => 'resize', 'end_date' => '2027-02-01'])
        ->assertUnprocessable()
        ->assertJsonPath('invalid_params.0.code', 'invalid_period');
});

it('deletes an allocation and gives its hours back to the week', function (): void {
    [$tenant, $employee] = rpPlanner($this);
    rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 6, ['crm_opportunity_id' => rpOpportunity($tenant)])->assertCreated();
    $second = rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 4, ['crm_opportunity_id' => rpOpportunity($tenant)])->assertCreated()->json('data.allocation.id');

    $this->deleteJson("/api/v1/resource-planning/allocations/{$second}")->assertNoContent();

    $list = $this->getJson('/api/v1/resource-planning/allocations?from=2027-02-01&to=2027-02-28')->json('data');
    expect($list)->toHaveCount(1)->and($list[0]['is_overloaded'])->toBeFalse();
    $this->deleteJson("/api/v1/resource-planning/allocations/{$second}")->assertNotFound();
});

it('keeps the weekly snapshots in step with the bookings', function (): void {
    [$tenant, $employee] = rpPlanner($this);
    rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 5, ['booking_type' => 'HardCommitted', 'project_id' => rpProject($tenant)])->assertCreated();
    rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 2, ['crm_opportunity_id' => rpOpportunity($tenant), 'is_billable' => false])->assertCreated();

    $snapshot = $this->getJson('/api/v1/resource-planning/capacity/snapshots?from='.RP_CLEAN_WEEK.'&to='.RP_CLEAN_WEEK."&employee_id={$employee}")->assertOk()->json('data.0');

    expect($snapshot['week_start_date'])->toBe(RP_CLEAN_WEEK)
        ->and($snapshot['contractual_hours'])->toEqual(40.0)
        ->and($snapshot['leave_and_holiday_hours'])->toEqual(0.0)
        ->and($snapshot['net_available_hours'])->toEqual(40.0)
        ->and($snapshot['billable_booked_hours'])->toEqual(25.0)
        ->and($snapshot['internal_booked_hours'])->toEqual(10.0)
        ->and($snapshot['utilization_rate_percent'])->toEqual(87.5);

    $this->postJson('/api/v1/resource-planning/capacity/snapshots/recalculate', ['from' => RP_CLEAN_WEEK, 'to' => '2027-02-21', 'employee_id' => $employee])
        ->assertOk()
        ->assertJsonPath('data.weeks', 2)
        ->assertJsonPath('data.snapshots', 2);
});

it('does not see the allocations of another tenant', function (): void {
    [$tenant, $employee] = rpPlanner($this);
    rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 4, ['crm_opportunity_id' => rpOpportunity($tenant)])->assertCreated();

    $other = rpTenant('IT');
    rpActAs($other, rpUser($other));

    expect($this->getJson('/api/v1/resource-planning/allocations')->assertOk()->json('data'))->toBe([]);
    rpAllocate($this, $employee, RP_CLEAN_WEEK, '2027-02-12', 4, ['crm_opportunity_id' => rpOpportunity($other)])->assertUnprocessable();
});
