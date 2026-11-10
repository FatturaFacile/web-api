<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\LeaveBalance;
use Modules\HumanResources\Models\Position;

uses(RefreshDatabase::class);

/**
 * GET /hr/me/leave-types (self-service labels for balances and requests) and the response shapes the OpenAPI declares
 * for the leave, training, succession and forecast endpoints.
 */
function shapesMember(Tenant $tenant, string $role = 'member'): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@example.ch', 'password' => bcrypt('secret123'), 'locale' => 'it']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function shapesAs(User $user, Tenant $tenant): mixed
{
    Sanctum::actingAs($user);

    return test()->withHeader('X-Tenant-Id', $tenant->id);
}

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    $this->tenant = Tenant::create(['name' => 'Ferie SA', 'legal_name' => 'Ferie SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->other = Tenant::create(['name' => 'Altra SA', 'legal_name' => 'Altra SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->hr = shapesMember($this->tenant, 'hr');
    $this->worker = shapesMember($this->tenant);
    $this->stranger = shapesMember($this->tenant);
    $this->employee = Employee::factory()->active()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->worker->id]);
    $this->vacation = shapesAs($this->hr, $this->tenant)->postJson('/api/v1/hr/leave-types', [
        'code' => 'VAC', 'name' => 'Ferie', 'category' => 'vacation', 'annual_entitlement_hours' => '160', 'accrual_frequency' => 'none',
    ])->assertCreated()->json('data.id');
});

test('an employee without HR access reads the leave types through the self-service route', function (): void {
    shapesAs($this->worker, $this->tenant)->getJson('/api/v1/hr/leave-types')->assertForbidden();

    $response = shapesAs($this->worker, $this->tenant)->getJson('/api/v1/hr/me/leave-types')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and(array_keys($response->json('data.0')))->toEqualCanonicalizing(['id', 'code', 'name', 'category', 'is_paid', 'requires_approval', 'requires_attachment', 'is_active', 'translations'])
        ->and($response->json('data.0.id'))->toBe($this->vacation)
        ->and($response->json('data.0.code'))->toBe('VAC')
        ->and($response->json('data.0'))->not->toHaveKey('annual_entitlement_hours');
});

test('the self-service leave types need an employee record linked to the user and stay inside the company', function (): void {
    app('auth')->forgetGuards();
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/hr/me/leave-types')->assertUnauthorized();

    // A member of the company without an employee record is refused, like on every other /hr/me route.
    shapesAs($this->stranger, $this->tenant)->getJson('/api/v1/hr/me/leave-types')->assertForbidden();
    shapesAs($this->stranger, $this->tenant)->getJson('/api/v1/hr/me/leave-balances')->assertForbidden();

    // An employee of another company sees the types of that company only, never those of this one.
    $foreigner = shapesMember($this->other);
    Employee::factory()->active()->create(['tenant_id' => $this->other->id, 'user_id' => $foreigner->id]);
    shapesAs($foreigner, $this->other)->getJson('/api/v1/hr/me/leave-types')->assertOk()->assertJsonCount(0, 'data');

    // And cannot reach this company at all.
    shapesAs($foreigner, $this->other)->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/hr/me/leave-types')->assertForbidden();
});

test('leave balances and leave requests carry hours and days as JSON numbers', function (): void {
    LeaveBalance::factory()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'leave_type_id' => $this->vacation, 'fiscal_year' => 2026,
        'entitlement_hours' => '80.00', 'accrued_hours' => '80.00', 'carryover_hours' => '0.00', 'used_hours' => '0.00', 'pending_hours' => '0.00', 'available_hours' => '80.00']);

    $balances = shapesAs($this->worker, $this->tenant)->getJson('/api/v1/hr/me/leave-balances')->assertOk();
    expect(array_keys($balances->json('data.0')))->toEqualCanonicalizing(['id', 'employee_id', 'leave_type_id', 'fiscal_year', 'entitlement_hours', 'carryover_hours', 'accrued_hours', 'used_hours', 'pending_hours', 'available_hours'])
        ->and($balances->json('data.0.available_hours'))->toBeNumeric()->not->toBeString();

    $request = shapesAs($this->worker, $this->tenant)->postJson('/api/v1/hr/me/leave-requests', ['leave_type_id' => $this->vacation, 'start_date' => '2026-12-21', 'end_date' => '2026-12-22', 'total_hours' => '12'])
        ->assertCreated();
    expect(array_keys($request->json('data')))->toEqualCanonicalizing(['id', 'request_number', 'employee_id', 'leave_type_id', 'start_date', 'end_date', 'total_hours', 'total_days', 'status', 'is_locked', 'reason', 'approved_at'])
        ->and($request->json('data.total_hours'))->not->toBeString()
        ->and($request->json('data.total_days'))->not->toBeString()
        ->and($request->json('data.status'))->toBeIn(['draft', 'pending_approval', 'approved', 'rejected', 'cancelled']);

    $list = shapesAs($this->worker, $this->tenant)->getJson('/api/v1/hr/me/leave-requests')->assertOk();
    expect($list->json('data.0.total_hours'))->not->toBeString();
});

test('training courses, completion, certification scan, succession and forecast answer as documented', function (): void {
    $api = fn () => shapesAs($this->hr, $this->tenant);

    $course = $api()->postJson('/api/v1/hr/training-courses', ['course_code' => 'SAF-1', 'title' => 'Sicurezza', 'validity_months' => 12])->assertCreated();
    expect($course->json('data.course_code'))->toBe('SAF-1')
        ->and($course->json('data.category'))->toBe('safety')
        ->and($course->json('data.provider'))->toBe('Enterprise Academy')
        ->and($course->json('data.is_active'))->toBeTrue();

    $record = $api()->postJson('/api/v1/hr/training-records', ['employee_id' => $this->employee->id, 'course_id' => $course->json('data.id')])->assertCreated()->json('data.id');
    $completed = $api()->postJson("/api/v1/hr/training-records/{$record}/complete", ['score' => 88.5])->assertOk();
    expect($completed->json('data.status'))->toBe('completed')
        ->and($completed->json('data.score'))->toBe(88.5)
        ->and($api()->postJson('/api/v1/hr/training-records/'.Str::uuid().'/complete')->status())->toBe(404);

    $default = $api()->postJson('/api/v1/hr/training-records', ['employee_id' => $this->employee->id, 'course_id' => $course->json('data.id')])->assertCreated()->json('data.id');
    expect($api()->postJson("/api/v1/hr/training-records/{$default}/complete")->assertOk()->json('data.score'))->toBe(100);

    $scan = $api()->postJson('/api/v1/hr/certifications/scan-expiring', ['within_days' => 400])->assertOk();
    expect(array_keys($scan->json('data')))->toBe(['expiring_certifications_alerted'])
        ->and($scan->json('data.expiring_certifications_alerted'))->toBeInt();
    expect(array_keys($api()->postJson('/api/v1/hr/certifications/scan-expiring')->assertOk()->json('data')))->toBe(['expiring_certifications_alerted']);

    $department = Department::factory()->create(['tenant_id' => $this->tenant->id]);
    $position = Position::factory()->create(['tenant_id' => $this->tenant->id, 'department_id' => $department->id]);
    $successor = $api()->patchJson("/api/v1/hr/positions/{$position->id}/successor", ['successor_employee_id' => $this->employee->id, 'readiness_level' => 'ready_now'])->assertOk();
    expect($successor->json('data.successor_employee_id'))->toBe($this->employee->id)
        ->and($successor->json('data.readiness_level'))->toBe('ready_now');
    $api()->patchJson('/api/v1/hr/positions/'.Str::uuid().'/successor', ['successor_employee_id' => $this->employee->id])->assertNotFound();

    $forecast = $api()->postJson('/api/v1/hr/payroll-forecast', ['period_code' => '2026-12'])->assertOk();
    expect(array_keys($forecast->json('data')))->toEqualCanonicalizing(['period_code', 'active_headcount', 'estimated_payroll_outflow', 'accrual_amount'])
        ->and($forecast->json('data.period_code'))->toBe('2026-12')
        ->and($forecast->json('data.active_headcount'))->toBeInt();

    // Without the HR module access none of them is reachable.
    shapesAs($this->worker, $this->tenant)->postJson('/api/v1/hr/payroll-forecast')->assertForbidden();
    shapesAs($this->worker, $this->tenant)->postJson('/api/v1/hr/training-courses', ['course_code' => 'X', 'title' => 'x'])->assertForbidden();
    shapesAs($this->worker, $this->tenant)->patchJson("/api/v1/hr/positions/{$position->id}/successor", ['successor_employee_id' => $this->employee->id])->assertForbidden();
});
