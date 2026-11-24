<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\HumanResources\Models\Shift;
use Modules\HumanResources\Models\ShiftType;

require_once __DIR__.'/ShiftPlanningTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    spDeferForeignKeys();
});

/**
 * A company with a manager, three employees with a login, a draft weekly roster and an 08:00-16:00 template.
 *
 * @return array<string, mixed>
 */
function mirrorRoster(): array
{
    $tenant = spTenant('CH');
    $users = [spUser($tenant), spUser($tenant), spUser($tenant)];

    return [
        'tenant' => $tenant,
        'manager' => spUser($tenant, 'owner'),
        'users' => $users,
        'employees' => [
            spEmployee($tenant, 'Anna Rossi', user: $users[0]),
            spEmployee($tenant, 'Bruno Bianchi', user: $users[1]),
            spEmployee($tenant, 'Carla Verdi', user: $users[2]),
        ],
        'period' => spPeriod($tenant),
        'day' => spTemplate($tenant, 'DAY', '08:00', '16:00'),
    ];
}

/**
 * @return list<Shift>
 */
function mirroredShifts(string $tenantId): array
{
    return Shift::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('source', 'roster')->orderBy('work_date')->get()->all();
}

it('mirrors the shifts of a published roster into the planned shifts of the time recording', function (): void {
    ['tenant' => $tenant, 'manager' => $manager, 'employees' => $employees, 'period' => $period, 'day' => $day] = mirrorRoster();
    spActAs($tenant, $manager);
    spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $employees[0])->assertCreated();
    spAssign($this, $period, $day, spMonday()->addDay()->format('Y-m-d'), null)->assertCreated();

    expect(mirroredShifts((string) $tenant->id))->toBe([]);

    $this->postJson("/api/v1/shift-planning/periods/{$period->id}/publish")->assertOk();

    $shifts = mirroredShifts((string) $tenant->id);

    expect($shifts)->toHaveCount(1)
        ->and((string) $shifts[0]->employee_id)->toBe($employees[0])
        ->and($shifts[0]->work_date->format('Y-m-d'))->toBe(spMonday()->format('Y-m-d'))
        ->and($shifts[0]->status)->toBe(Shift::StatusPublished)
        ->and($shifts[0]->planned_minutes)->toBe(480)
        ->and(ShiftType::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('code', 'ROSTER')->count())->toBe(1);
});

it('adds the mirrored shift of an open shift claimed after publication', function (): void {
    ['tenant' => $tenant, 'manager' => $manager, 'users' => $users, 'employees' => $employees, 'period' => $period, 'day' => $day] = mirrorRoster();
    spActAs($tenant, $manager);
    spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $employees[0])->assertCreated();
    $open = spAssign($this, $period, $day, spMonday()->addDay()->format('Y-m-d'), null)->assertCreated()->json('data.assignment.id');
    $this->postJson("/api/v1/shift-planning/periods/{$period->id}/publish")->assertOk();

    spActAs($tenant, $users[2]);
    $this->postJson("/api/v1/shift-planning/my/open-shifts/{$open}/claim")->assertOk();

    $shifts = mirroredShifts((string) $tenant->id);

    expect($shifts)->toHaveCount(2)
        ->and(collect($shifts)->map(fn (Shift $shift): string => (string) $shift->employee_id)->sort()->values()->all())->toBe(collect([$employees[0], $employees[2]])->sort()->values()->all());
});
