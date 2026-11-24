<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\WorkOrder;

require_once __DIR__.'/../ResourcePlanning/ResourcePlanningTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    rpDeferForeignKeys();
    $this->tenant = rpTenant('CH');
    app(TenantContext::class)->setTenant($this->tenant);
    $user = User::create(['name' => 'Dispatcher', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);
    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id);

    $this->employeeId = rpEmployee($this->tenant, 'Tina Technician');
    // The card of the technician lists no skill: everything he can do comes from the Mod 079 skill matrix.
    $this->technician = ServiceTechnician::factory()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->employeeId, 'skills' => [], 'is_available' => true]);
    $this->workOrder = WorkOrder::create([
        'tenant_id' => $this->tenant->id,
        'work_order_number' => 'WO-'.Str::random(8),
        'title' => 'Program the PLC',
        'work_type' => 'corrective',
        'priority' => 'high',
        'status' => 'unscheduled',
        'customer_id' => Contact::factory()->create(['tenant_id' => $this->tenant->id])->id,
        'required_skills' => ['PLC programming'],
        'currency' => 'CHF',
    ]);
});

/**
 * @return array<string, string>
 */
function skillMatrixDispatchPayload(object $test): array
{
    return ['technician_id' => (string) $test->technician->id, 'scheduled_start' => '2026-03-02T09:00:00Z', 'scheduled_end' => '2026-03-02T11:00:00Z'];
}

it('refuses a dispatch when neither the card nor the Mod 079 skill matrix has the required skill', function (): void {
    $this->postJson("/api/v1/fsm/work-orders/{$this->workOrder->id}/dispatch", skillMatrixDispatchPayload($this))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['skills']);
});

it('dispatches to a technician whose skill sits in the Mod 079 skill matrix and shows it on the board', function (): void {
    rpGiveSkill($this->tenant, $this->employeeId, rpSkill($this->tenant, 'PLC programming'), 3);

    $this->postJson("/api/v1/fsm/work-orders/{$this->workOrder->id}/dispatch", skillMatrixDispatchPayload($this))
        ->assertOk()
        ->assertJsonPath('data.status', 'dispatched');

    $board = $this->getJson('/api/v1/fsm/board?from=2026-03-01T00:00:00Z&to=2026-03-08T00:00:00Z')->assertOk()->json('data');

    expect($board['lanes'][0]['skills'])->toBe(['plc programming']);
});

it('ignores a matrix skill that expired before the visit', function (): void {
    rpGiveSkill($this->tenant, $this->employeeId, rpSkill($this->tenant, 'PLC programming'), 3, '2026-01-31');

    $this->postJson("/api/v1/fsm/work-orders/{$this->workOrder->id}/dispatch", skillMatrixDispatchPayload($this))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['skills']);

    expect(DB::table('rp_employee_skill_profiles')->where('employee_id', $this->employeeId)->count())->toBe(1);
});
