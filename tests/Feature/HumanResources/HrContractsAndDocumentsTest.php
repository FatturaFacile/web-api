<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\ContractSalaryComponent;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmployeeDocument;
use Modules\HumanResources\Models\EmploymentContract;
use Modules\HumanResources\Services\EmployeeDocumentService;
use Modules\Payroll\Models\CollectiveAgreement;
use Modules\Payroll\Models\CollectiveAgreementLevel;

uses(RefreshDatabase::class);

function hrPerson(Tenant $tenant, string $role = 'hr'): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@example.ch', 'password' => bcrypt('secret123'), 'locale' => 'it']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function hrAs(User $user, Tenant $tenant): mixed
{
    Sanctum::actingAs($user);

    return test()->withHeader('X-Tenant-Id', $tenant->id);
}

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    Storage::fake(config('filesystems.private_disk', config('filesystems.default', 'local')));
    $this->tenant = Tenant::create(['name' => 'Personale SA', 'legal_name' => 'Personale SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->other = Tenant::create(['name' => 'Altra SA', 'legal_name' => 'Altra SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->hr = hrPerson($this->tenant);
    $this->worker = hrPerson($this->tenant, 'member');
    $this->employee = Employee::factory()->active()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->worker->id, 'employment_type' => 'full_time']);
    $this->first = EmploymentContract::factory()->create([
        'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'start_date' => '2024-01-01', 'end_date' => null,
        'is_active' => true, 'status' => 'active', 'weekly_hours' => '42.00', 'base_annual_salary' => '70000.00',
    ]);
});

test('a new contract supersedes the active one, closes its components and updates the master data', function (): void {
    $agreement = CollectiveAgreement::factory()->create(['tenant_id' => $this->tenant->id]);
    $level = CollectiveAgreementLevel::factory()->create(['tenant_id' => $this->tenant->id, 'agreement_id' => $agreement->id]);
    ContractSalaryComponent::factory()->create(['tenant_id' => $this->tenant->id, 'contract_id' => $this->first->id, 'valid_from' => '2024-01-01']);

    $created = hrAs($this->hr, $this->tenant)->postJson("/api/v1/hr/employees/{$this->employee->id}/contracts", [
        'contract_type' => 'permanent', 'job_title' => 'Capo reparto', 'start_date' => '2026-07-01', 'weekly_hours' => '33.6', 'fte_percentage' => '80',
        'base_annual_salary' => '64000.00', 'employment_type' => 'part_time', 'collective_agreement_id' => $agreement->id, 'collective_agreement_level_id' => $level->id,
        'salary_components' => [['wage_type_code' => '1100', 'description' => 'Indennità di funzione', 'amount' => '250.00']],
    ])->assertCreated()
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.base_annual_salary', '64000.00')
        ->assertJsonPath('data.collective_agreement_level_id', $level->id)
        ->assertJsonPath('data.salary_components.0.amount', '250.00')
        ->assertJsonPath('data.salary_components.0.valid_from', '2026-07-01')
        ->json('data');

    $previous = $this->first->refresh();

    expect($previous->is_active)->toBeFalse()
        ->and($previous->status)->toBe('amended')
        ->and($previous->end_date->format('Y-m-d'))->toBe('2026-06-30')
        ->and($previous->superseded_by_contract_id)->toBe($created['id'])
        ->and(ContractSalaryComponent::withoutGlobalScopes()->where('contract_id', $previous->id)->sole()->valid_to->format('Y-m-d'))->toBe('2026-06-30')
        ->and($this->employee->refresh()->employment_type)->toBe('part_time')
        ->and((string) $this->employee->fte_ratio)->toBe('0.80')
        ->and(AuditLedgerEntry::withoutGlobalScopes()->where('resource_type', ContractSalaryComponent::class)->count())->toBeGreaterThan(0)
        ->and(str_contains(AuditLedgerEntry::withoutGlobalScopes()->where('resource_type', ContractSalaryComponent::class)->get()->toJson(), '250.00'))->toBeFalse();

    hrAs($this->hr, $this->tenant)->getJson("/api/v1/hr/employees/{$this->employee->id}/contracts")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $created['id']);
});

test('contracts are validated, history rows stay frozen and salary components follow the active contract', function (): void {
    $api = hrAs($this->hr, $this->tenant);
    $foreignAgreement = CollectiveAgreement::factory()->create(['tenant_id' => $this->other->id]);

    $api->postJson("/api/v1/hr/employees/{$this->employee->id}/contracts", ['contract_type' => 'forever', 'start_date' => '2026-13-01'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['contract_type', 'job_title', 'start_date', 'weekly_hours', 'base_annual_salary']);
    $api->postJson("/api/v1/hr/employees/{$this->employee->id}/contracts", ['contract_type' => 'permanent', 'job_title' => 'X', 'start_date' => '2026-07-01', 'weekly_hours' => '40', 'base_annual_salary' => '1', 'collective_agreement_id' => $foreignAgreement->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['collective_agreement_id']);
    $api->postJson("/api/v1/hr/employees/{$this->employee->id}/contracts", ['contract_type' => 'permanent', 'job_title' => 'X', 'start_date' => '2023-01-01', 'weekly_hours' => '40', 'base_annual_salary' => '1'])
        ->assertUnprocessable();

    $component = $api->postJson("/api/v1/hr/contracts/{$this->first->id}/salary-components", ['wage_type_code' => '1200', 'description' => 'Premio', 'amount' => '100.50'])
        ->assertCreated()->assertJsonPath('data.amount', '100.50')->json('data.id');
    $api->putJson("/api/v1/hr/salary-components/{$component}", ['amount' => '120'])->assertOk()->assertJsonPath('data.amount', '120.00');
    $api->putJson("/api/v1/hr/contracts/{$this->first->id}", ['notes' => 'Rinnovo previsto'])->assertOk()->assertJsonPath('data.notes', 'Rinnovo previsto');

    $api->postJson("/api/v1/hr/employees/{$this->employee->id}/contracts", ['contract_type' => 'permanent', 'job_title' => 'Y', 'start_date' => '2026-09-01', 'weekly_hours' => '40', 'base_annual_salary' => '72000'])->assertCreated();

    $api->putJson("/api/v1/hr/contracts/{$this->first->id}", ['notes' => 'Troppo tardi'])->assertUnprocessable();
    $api->putJson("/api/v1/hr/salary-components/{$component}", ['amount' => '1'])->assertUnprocessable();
    $api->deleteJson("/api/v1/hr/salary-components/{$component}")->assertUnprocessable();
});

test('members, read-only roles and other companies reach no contract', function (): void {
    $readOnly = hrPerson($this->tenant, 'accountant_readonly');
    $foreignHr = hrPerson($this->other);

    hrAs($this->worker, $this->tenant)->getJson("/api/v1/hr/employees/{$this->employee->id}/contracts")->assertForbidden();
    hrAs($readOnly, $this->tenant)->postJson("/api/v1/hr/contracts/{$this->first->id}/salary-components", ['wage_type_code' => '1', 'description' => 'x', 'amount' => '1'])->assertForbidden();
    hrAs($foreignHr, $this->other)->getJson("/api/v1/hr/employees/{$this->employee->id}/contracts")->assertNotFound();
    hrAs($foreignHr, $this->other)->putJson("/api/v1/hr/contracts/{$this->first->id}", ['notes' => 'x'])->assertNotFound();
});

test('the personnel file keeps health documents for HR and shows the employee only what HR made visible', function (): void {
    $api = hrAs($this->hr, $this->tenant);

    $health = $api->post("/api/v1/hr/employees/{$this->employee->id}/documents", [
        'file' => UploadedFile::fake()->create('idoneita.pdf', 20, 'application/pdf'), 'document_type' => 'medical_fitness', 'title' => 'Idoneità', 'contains_health_data' => '1', 'is_confidential' => '0',
    ])->assertCreated()->assertJsonPath('data.contains_health_data', true)->assertJsonPath('data.is_confidential', true)->assertJsonMissingPath('data.file_path')->json('data.id');
    $visible = $api->post("/api/v1/hr/employees/{$this->employee->id}/documents", [
        'file' => UploadedFile::fake()->create('contratto.pdf', 20, 'application/pdf'), 'document_type' => 'contract', 'title' => 'Contratto', 'visible_to_employee' => '1', 'retention_until' => '2036-12-31',
    ])->assertCreated()->json('data.id');

    $api->post("/api/v1/hr/employees/{$this->employee->id}/documents", ['document_type' => 'contract', 'title' => 'x', 'file' => UploadedFile::fake()->create('virus.exe', 1)])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    $api->getJson("/api/v1/hr/employees/{$this->employee->id}/documents")->assertOk()->assertJsonCount(2, 'data');
    $api->get("/api/v1/hr/employee-documents/{$health}/download")->assertOk();

    $self = hrAs($this->worker, $this->tenant);
    $self->getJson('/api/v1/hr/me/documents')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible);
    $self->get("/api/v1/hr/me/documents/{$visible}/download")->assertOk();
    $self->get("/api/v1/hr/me/documents/{$health}/download")->assertNotFound();
    $self->getJson("/api/v1/hr/employees/{$this->employee->id}/documents")->assertForbidden();
    $self->get("/api/v1/hr/employee-documents/{$health}/download")->assertForbidden();

    hrAs(hrPerson($this->other), $this->other)->get("/api/v1/hr/employee-documents/{$health}/download")->assertNotFound();
    hrAs($this->hr, $this->tenant)->deleteJson("/api/v1/hr/employee-documents/{$health}")->assertNoContent();
    expect(EmployeeDocument::withoutGlobalScopes()->withTrashed()->findOrFail($health)->trashed())->toBeTrue();
});

test('a payslip or a contract cannot get a retention date before the end of its statutory period', function (): void {
    $api = hrAs($this->hr, $this->tenant);
    $upload = fn (array $values) => $api->post("/api/v1/hr/employees/{$this->employee->id}/documents", ['file' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'), 'title' => 'Doc', ...$values]);

    $upload(['document_type' => 'payslip', 'retention_until' => '2030-12-31'])->assertUnprocessable()->assertJsonValidationErrors('retention_until');
    $upload(['document_type' => 'contract', 'issue_date' => '2020-03-01', 'retention_until' => '2030-12-30'])->assertUnprocessable()->assertJsonValidationErrors('retention_until');
    $upload(['document_type' => 'contract', 'issue_date' => '2020-03-01', 'retention_until' => '2030-12-31'])->assertCreated();
    $upload(['document_type' => 'certificate', 'retention_until' => '2027-01-31'])->assertCreated();
});

test('the retention purge erases expired documents with their files and leaves the rest alone', function (): void {
    $disk = Storage::disk(config('filesystems.private_disk', config('filesystems.default', 'local')));
    $expired = EmployeeDocument::factory()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'document_type' => 'certificate', 'file_path' => 'hr/expired.pdf', 'retention_until' => '2026-11-01']);
    $kept = EmployeeDocument::factory()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'file_path' => 'hr/kept.pdf', 'retention_until' => '2027-01-01']);
    $disk->put('hr/expired.pdf', 'x');
    $disk->put('hr/kept.pdf', 'y');

    expect(app(EmployeeDocumentService::class)->purgeExpired((string) $this->tenant->id))->toBe(1)
        ->and(EmployeeDocument::withoutGlobalScopes()->withTrashed()->find($expired->id))->toBeNull()
        ->and(EmployeeDocument::withoutGlobalScopes()->find($kept->id))->not->toBeNull();

    $disk->assertMissing('hr/expired.pdf');
    $disk->assertExists('hr/kept.pdf');
});

test('self-service needs an employee record linked to the user', function (): void {
    $stranger = hrPerson($this->tenant, 'member');

    hrAs($stranger, $this->tenant)->getJson('/api/v1/hr/me')->assertForbidden();
    hrAs($this->worker, $this->tenant)->getJson('/api/v1/hr/me')->assertOk()->assertJsonPath('data.id', $this->employee->id)->assertJsonMissingPath('data.base_annual_salary');
    hrAs($this->worker, $this->other)->getJson('/api/v1/hr/me')->assertForbidden();
});

test('departments and positions are validated within the company and cannot loop', function (): void {
    $api = fn () => hrAs($this->hr, $this->tenant);
    $foreignDepartment = Department::factory()->create(['tenant_id' => $this->other->id]);

    $root = $api()->postJson('/api/v1/hr/departments', ['code' => 'ROOT', 'name' => 'Direzione'])->assertCreated()->json('data.id');
    $child = $api()->postJson('/api/v1/hr/departments', ['code' => 'OPS', 'name' => 'Operazioni', 'parent_id' => $root])->assertCreated()->json('data.id');
    $api()->postJson('/api/v1/hr/departments', ['code' => 'ROOT', 'name' => 'Doppio'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $api()->postJson('/api/v1/hr/departments', ['code' => 'X', 'name' => 'Estero', 'parent_id' => $foreignDepartment->id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    $api()->putJson("/api/v1/hr/departments/{$root}", ['parent_id' => $child])->assertUnprocessable();
    $api()->putJson("/api/v1/hr/departments/{$root}", ['parent_id' => $root])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    $api()->putJson("/api/v1/hr/departments/{$child}", ['name' => 'Operations'])->assertOk()->assertJsonPath('data.name', 'Operations');

    $position = $api()->postJson('/api/v1/hr/positions', ['code' => 'P-1', 'title' => 'Capo turno', 'department_id' => $child])->assertCreated()->json('data.id');
    $api()->putJson("/api/v1/hr/positions/{$position}", ['headcount_budget' => 3, 'department_id' => $foreignDepartment->id])->assertUnprocessable()->assertJsonValidationErrors('department_id');
    $api()->putJson("/api/v1/hr/positions/{$position}", ['headcount_budget' => 3])->assertOk();

    hrAs($this->worker, $this->tenant)->putJson("/api/v1/hr/positions/{$position}", ['title' => 'x'])->assertForbidden();
    hrAs(hrPerson($this->other), $this->other)->putJson("/api/v1/hr/departments/{$child}", ['name' => 'x'])->assertNotFound();
});

test('a contract never ends or leaves probation before it starts', function (): void {
    $api = hrAs($this->hr, $this->tenant);

    $api->putJson("/api/v1/hr/contracts/{$this->first->id}", ['end_date' => '2023-12-31'])
        ->assertUnprocessable()->assertJsonValidationErrors('end_date');
    $api->putJson("/api/v1/hr/contracts/{$this->first->id}", ['probation_end_date' => '2023-12-31'])
        ->assertUnprocessable()->assertJsonValidationErrors('probation_end_date');
    $api->putJson("/api/v1/hr/contracts/{$this->first->id}", ['probation_end_date' => '2024-03-31', 'end_date' => '2027-12-31'])
        ->assertOk();
});

test('a document does not expire before it was issued', function (): void {
    $api = hrAs($this->hr, $this->tenant);
    $payload = fn (array $dates): array => ['file' => UploadedFile::fake()->create('permesso.pdf', 20, 'application/pdf'), 'document_type' => 'work_permit', 'title' => 'Permesso', ...$dates];

    $api->post("/api/v1/hr/employees/{$this->employee->id}/documents", $payload(['issue_date' => '2026-05-01', 'expiry_date' => '2026-04-30']))
        ->assertUnprocessable()->assertJsonValidationErrors('expiry_date');
    $api->post("/api/v1/hr/employees/{$this->employee->id}/documents", $payload(['issue_date' => '2026-05-01', 'expiry_date' => '2027-05-01']))
        ->assertCreated();
});
