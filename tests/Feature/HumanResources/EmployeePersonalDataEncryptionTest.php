<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\HumanResources\Models\Employee;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    app(TenantContext::class)->setTenant($this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('personal data of employees is encrypted at rest and readable through the model', function (): void {
    $employee = Employee::factory()->active()->create([
        'tenant_id' => $this->tenant->id,
        'tax_code' => 'RSSMRA85M01H501Q',
        'social_security_number' => '756.1234.5678.97',
        'phone' => '+41791234567',
        'personal_email' => 'mario@example.com',
    ]);

    $row = DB::table('hr_employees')->where('id', $employee->id)->first();

    expect($row->tax_code)->not->toContain('RSSMRA')
        ->and($row->social_security_number)->not->toContain('756.')
        ->and($row->phone)->not->toContain('+4179')
        ->and($row->personal_email)->not->toContain('mario')
        ->and($employee->fresh()->tax_code)->toBe('RSSMRA85M01H501Q');
});

test('the immutable audit ledger never receives personal data in clear', function (): void {
    $employee = Employee::factory()->active()->create(['tenant_id' => $this->tenant->id, 'tax_code' => 'RSSMRA85M01H501Q']);
    $employee->update(['tax_code' => 'VRDGPP70A01F205X']);
    $employee->delete();

    $payloads = DB::table('enterprise_audit_ledger')->where('entity_id', $employee->id)->get(['payload_before', 'payload_after'])->toJson();

    expect($payloads)->not->toContain('RSSMRA')->and($payloads)->not->toContain('VRDGPP');
});
