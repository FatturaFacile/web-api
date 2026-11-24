<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Contracts\LeaveAndTimeServiceInterface;
use Modules\HumanResources\Contracts\TrainingAndCertificationServiceInterface;
use Modules\HumanResources\Models\Certification;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\LeaveAccrualPosting;
use Modules\HumanResources\Models\LeaveBalance;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Notifications\CertificationExpiringNotification;
use Modules\Workflow\Actions\SeedDefaultWorkflowDefinitionsAction;
use Modules\Workflow\Models\WorkflowInstance;

uses(RefreshDatabase::class);

function leaveMember(Tenant $tenant, string $role = 'member'): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@example.ch', 'password' => bcrypt('secret123'), 'locale' => 'it']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function leaveAs(User $user, Tenant $tenant): mixed
{
    Sanctum::actingAs($user);

    return test()->withHeader('X-Tenant-Id', $tenant->id);
}

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    $this->tenant = Tenant::create(['name' => 'Ferie SA', 'legal_name' => 'Ferie SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->other = Tenant::create(['name' => 'Altra SA', 'legal_name' => 'Altra SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->hr = leaveMember($this->tenant, 'hr');
    $this->admin = leaveMember($this->tenant, 'admin');
    $this->worker = leaveMember($this->tenant, 'member');
    $this->employee = Employee::factory()->active()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->worker->id, 'fte_ratio' => '0.50', 'employment_type' => 'part_time', 'hourly_cost_rate' => '0']);
    $this->vacation = leaveAs($this->hr, $this->tenant)->postJson('/api/v1/hr/leave-types', [
        'code' => 'VAC', 'name' => 'Ferie', 'category' => 'vacation', 'annual_entitlement_hours' => '0', 'accrual_frequency' => 'none',
    ])->assertCreated()->json('data.id');
});

test('an accrual policy prorates by FTE, accrues once per month and honours the balance cap', function (): void {
    leaveAs($this->hr, $this->tenant)->postJson('/api/v1/hr/leave-accrual-policies', ['leave_type_id' => $this->vacation, 'code' => 'X', 'name' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors(['annual_entitlement_hours', 'valid_from']);
    leaveAs($this->hr, $this->tenant)->postJson('/api/v1/hr/leave-accrual-policies', [
        'leave_type_id' => $this->vacation, 'code' => 'PT', 'name' => 'Part-time', 'employment_type' => 'part_time', 'annual_entitlement_hours' => '240.00',
        'prorate_by_fte' => true, 'max_balance_hours' => '12.00', 'valid_from' => '2026-01-01', 'source_reference' => 'Contratto aziendale, art. 7 (dato di test)',
    ])->assertCreated();

    $service = app(LeaveAndTimeServiceInterface::class);
    app(TenantContext::class)->setTenant($this->tenant);
    $balance = $service->initializeLeaveBalance((string) $this->tenant->id, (string) $this->employee->id, $this->vacation, 2026, 0.0);

    expect($service->runMonthlyAccruals((string) $this->tenant->id, 2026, 10))->toBe(1)
        ->and((string) $balance->fresh()->accrued_hours)->toBe('10.00')
        ->and($service->runMonthlyAccruals((string) $this->tenant->id, 2026, 10))->toBe(0)
        ->and($service->runMonthlyAccruals((string) $this->tenant->id, 2026, 11))->toBe(1)
        ->and((string) $balance->fresh()->available_hours)->toBe('12.00')
        ->and((string) $balance->fresh()->accrued_hours)->toBe('12.00')
        ->and(LeaveAccrualPosting::withoutGlobalScopes()->where('leave_balance_id', $balance->id)->pluck('hours', 'period')->map(fn ($hours): string => (string) $hours)->all())
        ->toBe(['2026-10' => '10.00', '2026-11' => '2.00']);
});

test('a self-service leave request runs through the seeded approval workflow end to end', function (): void {
    app(SeedDefaultWorkflowDefinitionsAction::class)->execute((string) $this->tenant->id, ['HR_LEAVE_APPROVAL']);
    LeaveBalance::factory()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'leave_type_id' => $this->vacation, 'fiscal_year' => 2026,
        'entitlement_hours' => '80.00', 'accrued_hours' => '80.00', 'carryover_hours' => '0.00', 'used_hours' => '0.00', 'pending_hours' => '0.00', 'available_hours' => '80.00']);

    $request = leaveAs($this->worker, $this->tenant)->postJson('/api/v1/hr/me/leave-requests', [
        'leave_type_id' => $this->vacation, 'start_date' => '2026-12-21', 'end_date' => '2026-12-23', 'total_hours' => '12', 'reason' => 'Famiglia',
    ])->assertCreated()->assertJsonPath('data.status', 'pending_approval')->assertJsonPath('data.is_locked', true)->json('data');

    $leave = LeaveRequest::withoutGlobalScopes()->findOrFail($request['id']);
    $instance = WorkflowInstance::withoutGlobalScopes()->findOrFail($leave->workflow_instance_id);
    $balance = LeaveBalance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->sole();

    expect((string) $balance->pending_hours)->toBe('12.00')
        ->and((string) $balance->available_hours)->toBe('68.00');

    leaveAs($this->hr, $this->tenant)->postJson("/api/v1/hr/leave-requests/{$leave->id}/approve")->assertConflict();

    leaveAs($this->admin, $this->tenant)->postJson('/api/v1/workflows/steps/'.$instance->steps()->firstOrFail()->id.'/decide', ['decision' => 'approved', 'comment' => 'ok'])->assertOk();

    $balance->refresh();
    expect($leave->refresh()->status)->toBe('approved')
        ->and($leave->is_locked)->toBeFalse()
        ->and((string) $balance->used_hours)->toBe('12.00')
        ->and((string) $balance->pending_hours)->toBe('0.00')
        ->and(TransactionalOutbox::withoutGlobalScopes()->where('aggregate_id', $leave->id)->where('event_type', 'hr.leave.approved')->count())->toBe(1);

    leaveAs($this->worker, $this->tenant)->getJson('/api/v1/hr/me/leave-requests')->assertOk()->assertJsonPath('data.0.status', 'approved');
    leaveAs($this->worker, $this->tenant)->getJson('/api/v1/hr/me/leave-balances')->assertOk()->assertJsonPath('data.0.used_hours', 12);
});

test('a rejection through the workflow gives the hours back, and a pending request can be withdrawn', function (): void {
    app(SeedDefaultWorkflowDefinitionsAction::class)->execute((string) $this->tenant->id, ['HR_LEAVE_APPROVAL']);
    LeaveBalance::factory()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'leave_type_id' => $this->vacation, 'fiscal_year' => 2026,
        'entitlement_hours' => '40.00', 'accrued_hours' => '40.00', 'carryover_hours' => '0.00', 'used_hours' => '0.00', 'pending_hours' => '0.00', 'available_hours' => '40.00']);
    $self = fn () => leaveAs($this->worker, $this->tenant);

    $rejected = $self()->postJson('/api/v1/hr/me/leave-requests', ['leave_type_id' => $this->vacation, 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'total_hours' => '8'])->assertCreated()->json('data.id');
    $instance = WorkflowInstance::withoutGlobalScopes()->findOrFail(LeaveRequest::withoutGlobalScopes()->findOrFail($rejected)->workflow_instance_id);
    leaveAs($this->admin, $this->tenant)->postJson('/api/v1/workflows/steps/'.$instance->steps()->firstOrFail()->id.'/decide', ['decision' => 'rejected', 'comment' => 'periodo pieno'])->assertOk();

    $withdrawn = $self()->postJson('/api/v1/hr/me/leave-requests', ['leave_type_id' => $this->vacation, 'start_date' => '2026-12-10', 'end_date' => '2026-12-10', 'total_hours' => '8'])->assertCreated()->json('data.id');
    $self()->postJson("/api/v1/hr/me/leave-requests/{$withdrawn}/cancel", ['reason' => 'Cambio programma'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $self()->postJson("/api/v1/hr/me/leave-requests/{$withdrawn}/cancel", ['reason' => 'Ancora'])->assertConflict();

    $balance = LeaveBalance::withoutGlobalScopes()->where('employee_id', $this->employee->id)->sole();

    expect(LeaveRequest::withoutGlobalScopes()->findOrFail($rejected)->status)->toBe('rejected')
        ->and(WorkflowInstance::withoutGlobalScopes()->findOrFail(LeaveRequest::withoutGlobalScopes()->findOrFail($withdrawn)->workflow_instance_id)->status)->toBe('cancelled')
        ->and((string) $balance->pending_hours)->toBe('0.00')
        ->and((string) $balance->available_hours)->toBe('40.00');

    $self()->postJson('/api/v1/hr/me/leave-requests', ['leave_type_id' => $this->vacation, 'start_date' => '2026-12-10', 'end_date' => '2026-12-09', 'total_hours' => '999999'])
        ->assertUnprocessable()->assertJsonValidationErrors(['end_date', 'total_hours']);
    $self()->postJson('/api/v1/hr/me/leave-requests', ['leave_type_id' => $this->vacation, 'start_date' => '2026-12-14', 'end_date' => '2026-12-18', 'total_hours' => '400'])
        ->assertUnprocessable();
});

test('without a workflow HR decides, a type without approval is approved at once and the calendar shows no reasons', function (): void {
    $sick = leaveAs($this->hr, $this->tenant)->postJson('/api/v1/hr/leave-types', ['code' => 'SICK', 'name' => 'Malattia', 'category' => 'sick', 'annual_entitlement_hours' => '0', 'requires_approval' => false])->assertCreated()->json('data.id');
    leaveAs($this->hr, $this->tenant)->putJson("/api/v1/hr/leave-types/{$this->vacation}", ['annual_entitlement_hours' => '160'])->assertOk();
    leaveAs($this->hr, $this->tenant)->postJson('/api/v1/hr/leave-types', ['code' => 'SICK', 'name' => 'Doppio', 'category' => 'sick', 'annual_entitlement_hours' => '0'])->assertUnprocessable()->assertJsonValidationErrors('code');

    $pending = leaveAs($this->worker, $this->tenant)->postJson('/api/v1/hr/me/leave-requests', ['leave_type_id' => $this->vacation, 'start_date' => '2026-11-16', 'end_date' => '2026-11-17', 'total_hours' => '8'])
        ->assertCreated()->assertJsonPath('data.is_locked', false)->json('data.id');
    leaveAs($this->worker, $this->tenant)->postJson('/api/v1/hr/me/leave-requests', ['leave_type_id' => $sick, 'start_date' => '2026-11-09', 'end_date' => '2026-11-09', 'total_hours' => '4', 'medical_certificate_ref' => 'CERT-123'])
        ->assertCreated()->assertJsonPath('data.status', 'approved');

    leaveAs($this->worker, $this->tenant)->postJson("/api/v1/hr/leave-requests/{$pending}/approve")->assertForbidden();
    leaveAs($this->hr, $this->tenant)->postJson("/api/v1/hr/leave-requests/{$pending}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

    $calendar = leaveAs($this->hr, $this->tenant)->getJson('/api/v1/hr/leave-calendar?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonCount(2, 'data');
    expect($calendar->getContent())->not->toContain('CERT-123');

    leaveAs($this->hr, $this->tenant)->postJson("/api/v1/hr/leave-requests/{$pending}/cancel", ['reason' => 'Annullata dal responsabile'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    leaveAs($this->worker, $this->tenant)->getJson('/api/v1/hr/leave-calendar?from=2026-11-01&to=2026-11-30')->assertForbidden();
    leaveAs($this->hr, $this->tenant)->getJson('/api/v1/hr/leave-calendar?from=2026-11-30&to=2026-11-01')->assertUnprocessable();
});

test('leave configuration and requests are isolated by company and closed to members and read-only roles', function (): void {
    $foreignHr = leaveMember($this->other, 'hr');
    $readOnly = leaveMember($this->tenant, 'accountant_readonly');
    $request = LeaveRequest::factory()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'leave_type_id' => $this->vacation, 'status' => 'pending_approval', 'is_locked' => false]);

    leaveAs($foreignHr, $this->other)->putJson("/api/v1/hr/leave-types/{$this->vacation}", ['name' => 'x'])->assertNotFound();
    leaveAs($foreignHr, $this->other)->postJson("/api/v1/hr/leave-requests/{$request->id}/cancel", ['reason' => 'abc'])->assertNotFound();
    leaveAs($foreignHr, $this->other)->postJson('/api/v1/hr/leave-accrual-policies', ['leave_type_id' => $this->vacation, 'code' => 'F', 'name' => 'f', 'annual_entitlement_hours' => '1', 'valid_from' => '2026-01-01'])
        ->assertUnprocessable()->assertJsonValidationErrors('leave_type_id');
    leaveAs($this->worker, $this->tenant)->postJson('/api/v1/hr/leave-types', ['code' => 'Z', 'name' => 'z', 'category' => 'other', 'annual_entitlement_hours' => '1'])->assertForbidden();
    leaveAs($readOnly, $this->tenant)->postJson('/api/v1/hr/leave-accrual-policies', [])->assertForbidden();

    $colleague = Employee::factory()->active()->create(['tenant_id' => $this->tenant->id]);
    $theirs = LeaveRequest::factory()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $colleague->id, 'leave_type_id' => $this->vacation, 'status' => 'pending_approval']);
    leaveAs($this->worker, $this->tenant)->postJson("/api/v1/hr/me/leave-requests/{$theirs->id}/cancel", ['reason' => 'non mia'])->assertNotFound();
    leaveAs($this->worker, $this->tenant)->getJson('/api/v1/hr/me/leave-requests')->assertOk()->assertJsonCount(1, 'data');
});

test('expiring certifications notify the employee and HR once per status change', function (): void {
    app(TenantContext::class)->setTenant($this->tenant);
    $certification = Certification::factory()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'code' => 'SAFE-01', 'name' => 'Sicurezza cantiere', 'expiry_date' => '2026-11-20', 'status' => 'active']);
    $service = app(TrainingAndCertificationServiceInterface::class);

    expect($service->scanExpiringCertifications((string) $this->tenant->id, 30))->toBe(1)
        ->and($service->scanExpiringCertifications((string) $this->tenant->id, 30))->toBe(0)
        ->and($certification->refresh()->status)->toBe('expiring_soon')
        ->and($this->worker->notifications()->count())->toBe(1)
        ->and($this->hr->notifications()->count())->toBe(1)
        ->and($this->hr->notifications()->first()?->type)->toBe(CertificationExpiringNotification::class);

    $this->travelTo('2026-11-25 10:00:00');

    expect($service->scanExpiringCertifications((string) $this->tenant->id, 30))->toBe(1)
        ->and($certification->refresh()->status)->toBe('expired')
        ->and($this->worker->notifications()->count())->toBe(2);
});
