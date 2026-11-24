<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Payroll\Calculation\Swiss\SwissWageTypeCatalog;
use Modules\Payroll\Listeners\FeedPublishedRosterIntoSwissPayroll;
use Modules\Payroll\Models\PayrollWageType;

require_once __DIR__.'/ShiftPlanningTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    spDeferForeignKeys();
});

/**
 * @return list<object>
 */
function rosterInputs(string $tenantId): array
{
    return DB::table('payroll_inputs')->where('tenant_id', $tenantId)->where('note', 'like', 'ROSTER: %')->orderBy('wage_type_code')->get()->all();
}

it('feeds the night surcharge of a published Swiss roster into the payroll inputs, once per period', function (): void {
    $tenant = spTenant('CH');
    $manager = spUser($tenant, 'owner');
    $employee = spEmployee($tenant, 'Anna Rossi', hourlyCost: 20.0);
    $period = spPeriod($tenant);
    $night = spTemplate($tenant, 'NIGHT', '22:00', '06:00');
    spActAs($tenant, $manager);
    spAssign($this, $period, $night, spMonday()->format('Y-m-d'), $employee)->assertCreated();

    expect(rosterInputs((string) $tenant->id))->toBe([]);

    $this->postJson("/api/v1/shift-planning/periods/{$period->id}/publish")->assertOk();

    $inputs = rosterInputs((string) $tenant->id);

    // 420 night minutes = 7 h x (20 x 25%) = 35.
    expect($inputs)->toHaveCount(1)
        ->and($inputs[0]->wage_type_code)->toBe(SwissWageTypeCatalog::RosterNightSurcharge)
        ->and($inputs[0]->employee_id)->toBe($employee)
        ->and((float) $inputs[0]->quantity)->toBe(7.0)
        ->and((float) $inputs[0]->amount)->toBe(35.0)
        ->and(PayrollWageType::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('code', SwissWageTypeCatalog::RosterNightSurcharge)->exists())->toBeTrue();

    app(FeedPublishedRosterIntoSwissPayroll::class)->sync((string) $tenant->id, spMonday()->format('Y-m-d'), spMonday()->addDays(6)->format('Y-m-d'));

    expect(rosterInputs((string) $tenant->id))->toHaveCount(1);
});

it('does not feed Swiss payroll from Italian rosters', function (): void {
    $tenant = spTenant('IT');
    $manager = spUser($tenant, 'owner');
    $employee = spEmployee($tenant);
    $period = spPeriod($tenant, country: 'IT');
    $night = spTemplate($tenant, 'NIGHT', '22:00', '06:00');
    spActAs($tenant, $manager);
    spAssign($this, $period, $night, spMonday()->format('Y-m-d'), $employee)->assertCreated();
    $this->postJson("/api/v1/shift-planning/periods/{$period->id}/publish")->assertOk();

    expect(rosterInputs((string) $tenant->id))->toBe([]);

    $feed = app(FeedPublishedRosterIntoSwissPayroll::class);
    $monday = spMonday();

    expect($feed->sync((string) $tenant->id, $monday->format('Y-m-d'), $monday->format('Y-m-d')))->toBe(0);
});
