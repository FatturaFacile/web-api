<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Modules\ShiftPlanning\Domain\Enums\RosterStatus;
use Modules\ShiftPlanning\Domain\Events\ShiftAssignmentFinalizedEvent;
use Modules\ShiftPlanning\Infrastructure\Models\ScheduledShiftAssignment;
use Modules\ShiftPlanning\Infrastructure\Notifications\ShiftPlanningNotification;

require_once __DIR__.'/ShiftPlanningTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    spDeferForeignKeys();
});

/**
 * A published roster with two employees (each a user with an employee record), a manager and one shift template.
 *
 * @return array<string, mixed>
 */
function spRoster(object $test): array
{
    $tenant = spTenant('CH');
    $manager = spUser($tenant, 'owner');
    $users = [spUser($tenant), spUser($tenant), spUser($tenant)];
    $employees = [
        spEmployee($tenant, 'Anna Rossi', user: $users[0]),
        spEmployee($tenant, 'Bruno Bianchi', user: $users[1]),
        spEmployee($tenant, 'Carla Verdi', user: $users[2]),
    ];

    return ['tenant' => $tenant, 'manager' => $manager, 'users' => $users, 'employees' => $employees, 'period' => spPeriod($tenant), 'day' => spTemplate($tenant, 'DAY', '08:00', '16:00')];
}

function spPublish(object $test, array $roster): void
{
    spActAs($roster['tenant'], $roster['manager']);
    $test->postJson("/api/v1/shift-planning/periods/{$roster['period']->id}/publish")->assertOk();
}

it('lists the own published shifts without the labour cost and only after publication', function (): void {
    $roster = spRoster($this);
    ['tenant' => $tenant, 'manager' => $manager, 'users' => $users, 'employees' => $employees, 'period' => $period, 'day' => $day] = $roster;
    spActAs($tenant, $manager);
    spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $employees[0])->assertCreated();
    spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $employees[1])->assertCreated();

    spActAs($tenant, $users[0]);
    $window = '?from='.spMonday()->format('Y-m-d').'&to='.spMonday()->addDays(6)->format('Y-m-d');
    expect($this->getJson('/api/v1/shift-planning/my/shifts'.$window)->assertOk()->json('data'))->toBe([]);

    spPublish($this, $roster);
    spActAs($tenant, $users[0]);
    $mine = $this->getJson('/api/v1/shift-planning/my/shifts'.$window)->assertOk()->json('data');

    expect($mine)->toHaveCount(1)
        ->and($mine[0]['employee_id'])->toBe($employees[0])
        ->and($mine[0])->not->toHaveKey('estimated_labor_cost')
        ->and($mine[0]['surcharge_breakdown']['tags'])->toBe([])
        ->and($mine[0]['template']['code'])->toBe('DAY');
});

it('lists only the open shifts the employee is qualified for and lets one employee claim a shift', function (): void {
    Event::fake([ShiftAssignmentFinalizedEvent::class]);
    $roster = spRoster($this);
    ['tenant' => $tenant, 'manager' => $manager, 'users' => $users, 'employees' => $employees, 'period' => $period, 'day' => $day] = $roster;
    $forklift = spSkill($tenant);
    spGiveSkill($tenant, $employees[0], $forklift);
    spCertification($tenant, $employees[1], spMonday()->subDay()->format('Y-m-d'));
    spActAs($tenant, $manager);
    $plain = spAssign($this, $period, $day, spMonday()->format('Y-m-d'), null)->assertCreated()->json('data.assignment.id');
    $skilled = spAssign($this, $period, $day, spMonday()->addDay()->format('Y-m-d'), null, $forklift)->assertCreated()->json('data.assignment.id');
    spPublish($this, $roster);

    spActAs($tenant, $users[0]);
    $anna = collect($this->getJson('/api/v1/shift-planning/my/open-shifts?from='.spMonday()->format('Y-m-d').'&to='.spMonday()->addDays(6)->format('Y-m-d'))->assertOk()->json('data'))->pluck('id')->all();
    spActAs($tenant, $users[2]);
    $carla = collect($this->getJson('/api/v1/shift-planning/my/open-shifts?from='.spMonday()->format('Y-m-d').'&to='.spMonday()->addDays(6)->format('Y-m-d'))->assertOk()->json('data'))->pluck('id')->all();
    spActAs($tenant, $users[1]);
    $bruno = $this->getJson('/api/v1/shift-planning/my/open-shifts?from='.spMonday()->format('Y-m-d').'&to='.spMonday()->addDays(6)->format('Y-m-d'))->assertOk()->json('data');

    expect($anna)->toEqualCanonicalizing([$plain, $skilled])
        ->and($carla)->toBe([$plain])
        ->and($bruno)->toBe([]);

    $this->postJson("/api/v1/shift-planning/my/open-shifts/{$plain}/claim")->assertStatus(422);
    spActAs($tenant, $users[2]);
    $this->postJson("/api/v1/shift-planning/my/open-shifts/{$skilled}/claim")->assertStatus(422);
    $claimed = $this->postJson("/api/v1/shift-planning/my/open-shifts/{$plain}/claim")->assertOk();

    expect($claimed->json('data.assignment.employee_id'))->toBe($employees[2])
        ->and($claimed->json('data.assignment.is_open'))->toBeFalse();
    Event::assertDispatched(ShiftAssignmentFinalizedEvent::class, fn (ShiftAssignmentFinalizedEvent $event): bool => $event->assignmentId === $plain && $event->employeeId === $employees[2]);

    spActAs($tenant, $users[0]);
    $this->postJson("/api/v1/shift-planning/my/open-shifts/{$plain}/claim")->assertStatus(409);
});

it('refuses to claim an open shift of a draft roster', function (): void {
    $roster = spRoster($this);
    ['tenant' => $tenant, 'manager' => $manager, 'users' => $users, 'period' => $period, 'day' => $day] = $roster;
    spActAs($tenant, $manager);
    $open = spAssign($this, $period, $day, spMonday()->format('Y-m-d'), null)->assertCreated()->json('data.assignment.id');

    spActAs($tenant, $users[0]);
    $this->postJson("/api/v1/shift-planning/my/open-shifts/{$open}/claim")->assertStatus(422);
});

it('refuses a claim that breaks a statutory rule and names the rule code', function (): void {
    $roster = spRoster($this);
    ['tenant' => $tenant, 'manager' => $manager, 'users' => $users, 'employees' => $employees, 'period' => $period] = $roster;
    $night = spTemplate($tenant, 'NIGHT', '22:00', '06:00');
    spActAs($tenant, $manager);
    spAssign($this, $period, $night, spMonday()->format('Y-m-d'), $employees[0])->assertCreated();
    $morning = spTemplate($tenant, 'EARLY', '06:00', '14:00');
    $open = spAssign($this, $period, $morning, spMonday()->addDay()->format('Y-m-d'), null)->assertCreated()->json('data.assignment.id');
    spPublish($this, $roster);

    spActAs($tenant, $users[0]);
    $response = $this->postJson("/api/v1/shift-planning/my/open-shifts/{$open}/claim")->assertStatus(422);

    expect(spRuleCodes($response))->toContain('CH.DAILY_REST_11H');
});

it('runs the two-step swap: the colleague accepts, the manager approves, the shift changes hands', function (): void {
    Notification::fake();
    Event::fake([ShiftAssignmentFinalizedEvent::class]);
    $roster = spRoster($this);
    ['tenant' => $tenant, 'manager' => $manager, 'users' => $users, 'employees' => $employees, 'period' => $period, 'day' => $day] = $roster;
    spActAs($tenant, $manager);
    $offered = spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $employees[0])->assertCreated()->json('data.assignment.id');
    spPublish($this, $roster);

    spActAs($tenant, $users[0]);
    $swap = $this->postJson('/api/v1/shift-planning/my/swaps', ['shift_assignment_id' => $offered, 'target_peer_employee_id' => $employees[1]])->assertCreated();
    $id = $swap->json('data.id');
    expect($swap->json('data.status'))->toBe('pending_peer_acceptance');
    Notification::assertSentTo($users[1], ShiftPlanningNotification::class, fn ($notification): bool => $notification->toArray($users[1])['translation_key'] === 'SHIFT_PLANNING.NOTIFY_SWAP_REQUESTED');

    spActAs($tenant, $users[2]);
    $this->postJson("/api/v1/shift-planning/my/swaps/{$id}/respond", ['decision' => 'approve'])->assertForbidden();

    spActAs($tenant, $users[1]);
    expect($this->getJson('/api/v1/shift-planning/my/swaps')->assertOk()->json('data.0.assignment'))->not->toHaveKey('estimated_labor_cost');
    $accepted = $this->postJson("/api/v1/shift-planning/my/swaps/{$id}/respond", ['decision' => 'approve'])->assertOk();
    expect($accepted->json('data.status'))->toBe('pending_manager_approval');

    spActAs($tenant, $manager);
    $inbox = $this->getJson('/api/v1/shift-planning/swaps?status=pending_manager_approval')->assertOk()->json('data');
    expect($inbox)->toHaveCount(1)
        ->and($inbox[0]['compliance_impact']['can_approve'])->toBeTrue()
        ->and($inbox[0]['compliance_impact']['peer_takes_offered_shift']['new_employee_id'])->toBe($employees[1])
        ->and($inbox[0]['compliance_impact']['requester_takes_counter_shift'])->toBeNull();

    $approved = $this->postJson("/api/v1/shift-planning/swaps/{$id}/decision", ['decision' => 'approve'])->assertOk();

    expect($approved->json('data.status'))->toBe('approved')
        ->and($approved->json('data.reviewed_by_user_id'))->toBe($manager->id)
        ->and(ScheduledShiftAssignment::query()->findOrFail($offered)->employee_id)->toBe($employees[1]);
    Event::assertDispatched(ShiftAssignmentFinalizedEvent::class, fn (ShiftAssignmentFinalizedEvent $event): bool => $event->assignmentId === $offered && $event->employeeId === $employees[1]);
    Notification::assertSentTo($users[0], ShiftPlanningNotification::class, fn ($notification): bool => $notification->toArray($users[0])['translation_key'] === 'SHIFT_PLANNING.NOTIFY_SWAP_APPROVED');

    $this->postJson("/api/v1/shift-planning/swaps/{$id}/decision", ['decision' => 'approve'])->assertStatus(409);
});

it('exchanges two shifts one to one and swaps both owners', function (): void {
    $roster = spRoster($this);
    ['tenant' => $tenant, 'manager' => $manager, 'users' => $users, 'employees' => $employees, 'period' => $period, 'day' => $day] = $roster;
    spActAs($tenant, $manager);
    $mine = spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $employees[0])->assertCreated()->json('data.assignment.id');
    $theirs = spAssign($this, $period, $day, spMonday()->addDay()->format('Y-m-d'), $employees[1])->assertCreated()->json('data.assignment.id');
    spPublish($this, $roster);

    spActAs($tenant, $users[0]);
    $id = $this->postJson('/api/v1/shift-planning/my/swaps', ['shift_assignment_id' => $mine, 'target_peer_employee_id' => $employees[1], 'target_shift_assignment_id' => $theirs])->assertCreated()->json('data.id');
    spActAs($tenant, $users[1]);
    $this->postJson("/api/v1/shift-planning/my/swaps/{$id}/respond", ['decision' => 'approve'])->assertOk();
    spActAs($tenant, $manager);
    $this->postJson("/api/v1/shift-planning/swaps/{$id}/decision", ['decision' => 'approve'])->assertOk();

    expect(ScheduledShiftAssignment::query()->findOrFail($mine)->employee_id)->toBe($employees[1])
        ->and(ScheduledShiftAssignment::query()->findOrFail($theirs)->employee_id)->toBe($employees[0]);
});

it('lets the first qualified colleague accept a broadcast swap', function (): void {
    $roster = spRoster($this);
    ['tenant' => $tenant, 'manager' => $manager, 'users' => $users, 'employees' => $employees, 'period' => $period, 'day' => $day] = $roster;
    spActAs($tenant, $manager);
    $offered = spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $employees[0])->assertCreated()->json('data.assignment.id');
    spPublish($this, $roster);

    spActAs($tenant, $users[0]);
    $id = $this->postJson('/api/v1/shift-planning/my/swaps', ['shift_assignment_id' => $offered])->assertCreated()->json('data.id');
    spActAs($tenant, $users[1]);
    expect(collect($this->getJson('/api/v1/shift-planning/my/swaps')->json('data'))->pluck('id')->all())->toBe([$id]);
    $this->postJson("/api/v1/shift-planning/my/swaps/{$id}/respond", ['decision' => 'reject'])->assertOk()->assertJsonPath('data.status', 'pending_peer_acceptance');
    spActAs($tenant, $users[2]);
    $accepted = $this->postJson("/api/v1/shift-planning/my/swaps/{$id}/respond", ['decision' => 'approve'])->assertOk();

    expect($accepted->json('data'))->toMatchArray(['status' => 'pending_manager_approval', 'target_peer_employee_id' => $employees[2], 'is_broadcast' => false]);
    spActAs($tenant, $users[0]);
    $this->postJson("/api/v1/shift-planning/my/swaps/{$id}/respond", ['decision' => 'approve'])->assertStatus(409);
});

it('rejects a swap when the colleague declines or the manager gives a reason', function (): void {
    $roster = spRoster($this);
    ['tenant' => $tenant, 'manager' => $manager, 'users' => $users, 'employees' => $employees, 'period' => $period, 'day' => $day] = $roster;
    spActAs($tenant, $manager);
    $first = spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $employees[0])->assertCreated()->json('data.assignment.id');
    $second = spAssign($this, $period, $day, spMonday()->addDay()->format('Y-m-d'), $employees[0])->assertCreated()->json('data.assignment.id');
    spPublish($this, $roster);

    spActAs($tenant, $users[0]);
    $declined = $this->postJson('/api/v1/shift-planning/my/swaps', ['shift_assignment_id' => $first, 'target_peer_employee_id' => $employees[1]])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/shift-planning/my/swaps', ['shift_assignment_id' => $first, 'target_peer_employee_id' => $employees[2]])->assertStatus(409);
    spActAs($tenant, $users[1]);
    $this->postJson("/api/v1/shift-planning/my/swaps/{$declined}/respond", ['decision' => 'reject'])->assertOk()->assertJsonPath('data.status', 'rejected');

    spActAs($tenant, $users[0]);
    $pending = $this->postJson('/api/v1/shift-planning/my/swaps', ['shift_assignment_id' => $second, 'target_peer_employee_id' => $employees[1]])->assertCreated()->json('data.id');
    spActAs($tenant, $users[1]);
    $this->postJson("/api/v1/shift-planning/my/swaps/{$pending}/respond", ['decision' => 'approve'])->assertOk();

    spActAs($tenant, $manager);
    $this->postJson("/api/v1/shift-planning/swaps/{$pending}/decision", ['decision' => 'reject'])->assertStatus(422)->assertJsonValidationErrors('rejection_reason');
    $rejected = $this->postJson("/api/v1/shift-planning/swaps/{$pending}/decision", ['decision' => 'reject', 'rejection_reason' => 'Understaffed on that day'])->assertOk();

    expect($rejected->json('data'))->toMatchArray(['status' => 'rejected', 'rejection_reason' => 'Understaffed on that day'])
        ->and(ScheduledShiftAssignment::query()->findOrFail($second)->employee_id)->toBe($employees[0]);
});

it('stops a colleague from accepting a shift that conflicts with an approved leave', function (): void {
    $roster = spRoster($this);
    ['tenant' => $tenant, 'manager' => $manager, 'users' => $users, 'employees' => $employees, 'period' => $period, 'day' => $day] = $roster;
    spActAs($tenant, $manager);
    $offered = spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $employees[0])->assertCreated()->json('data.assignment.id');
    spPublish($this, $roster);
    spLeave($tenant, $employees[1], spMonday()->format('Y-m-d'), spMonday()->format('Y-m-d'));

    spActAs($tenant, $users[0]);
    $id = $this->postJson('/api/v1/shift-planning/my/swaps', ['shift_assignment_id' => $offered, 'target_peer_employee_id' => $employees[1]])->assertCreated()->json('data.id');
    spActAs($tenant, $users[1]);
    $blocked = $this->postJson("/api/v1/shift-planning/my/swaps/{$id}/respond", ['decision' => 'approve'])->assertStatus(422);

    expect(spRuleCodes($blocked))->toContain('LEAVE.APPROVED_CONFLICT');
    spActAs($tenant, $manager);
    expect($this->getJson('/api/v1/shift-planning/swaps')->json('data.0.status'))->toBe('pending_peer_acceptance');
});

it('re-validates both employees when the manager approves and keeps the swap pending on a blocking rule', function (): void {
    $roster = spRoster($this);
    ['tenant' => $tenant, 'manager' => $manager, 'users' => $users, 'employees' => $employees, 'period' => $period, 'day' => $day] = $roster;
    spActAs($tenant, $manager);
    $offered = spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $employees[0])->assertCreated()->json('data.assignment.id');
    spPublish($this, $roster);

    spActAs($tenant, $users[0]);
    $id = $this->postJson('/api/v1/shift-planning/my/swaps', ['shift_assignment_id' => $offered, 'target_peer_employee_id' => $employees[1]])->assertCreated()->json('data.id');
    spActAs($tenant, $users[1]);
    $this->postJson("/api/v1/shift-planning/my/swaps/{$id}/respond", ['decision' => 'approve'])->assertOk();

    // The colleague's mandatory certificate expires between the acceptance and the approval.
    spCertification($tenant, $employees[1], spMonday()->subDay()->format('Y-m-d'));
    spActAs($tenant, $manager);
    $inbox = $this->getJson('/api/v1/shift-planning/swaps')->json('data.0');
    $blocked = $this->postJson("/api/v1/shift-planning/swaps/{$id}/decision", ['decision' => 'approve'])->assertStatus(422);

    expect($inbox['compliance_impact']['can_approve'])->toBeFalse()
        ->and(spRuleCodes($blocked))->toBe(['SAFETY.CERTIFICATION_INVALID'])
        ->and(ScheduledShiftAssignment::query()->findOrFail($offered)->employee_id)->toBe($employees[0])
        ->and($this->getJson('/api/v1/shift-planning/swaps')->json('data.0.status'))->toBe('pending_manager_approval');
});

it('does not let an employee swap a shift of somebody else or a shift of a draft roster', function (): void {
    $roster = spRoster($this);
    ['tenant' => $tenant, 'manager' => $manager, 'users' => $users, 'employees' => $employees, 'period' => $period, 'day' => $day] = $roster;
    spActAs($tenant, $manager);
    $others = spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $employees[1])->assertCreated()->json('data.assignment.id');

    spActAs($tenant, $users[1]);
    $this->postJson('/api/v1/shift-planning/my/swaps', ['shift_assignment_id' => $others, 'target_peer_employee_id' => $employees[2]])->assertStatus(422);

    spPublish($this, $roster);
    spActAs($tenant, $users[0]);
    $this->postJson('/api/v1/shift-planning/my/swaps', ['shift_assignment_id' => $others, 'target_peer_employee_id' => $employees[2]])->assertForbidden();
    $this->postJson('/api/v1/shift-planning/my/swaps', ['shift_assignment_id' => $others, 'target_peer_employee_id' => $employees[0]])->assertForbidden();
    expect($period->refresh()->status)->toBe(RosterStatus::Published);
});
