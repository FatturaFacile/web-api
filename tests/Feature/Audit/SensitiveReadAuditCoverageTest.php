<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\BankAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->owner] = tfrTenant('Audit Coverage Tenant');
});

test('sensitive reads and exports are guarded by audit.access with the expected action', function (string $routeName, string $expected): void {
    $route = Route::getRoutes()->getByName($routeName);

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain($expected);
})->with([
    'passive invoices' => ['purchases.passive-invoices', 'audit.access:PassiveInvoice,VIEWED'],
    'bank account show' => ['bank-accounts.show', 'audit.access:BankAccount,VIEWED,,bank_account'],
    'bank transactions' => ['treasury.movements.index', 'audit.access:BankTransaction,VIEWED'],
    'payslip show' => ['api.v1.payroll.payslips.show', 'audit.access:Payslip,VIEWED'],
    'payslip pdf' => ['api.v1.payroll.payslips.pdf', 'audit.access:Payslip,EXPORTED,pdf'],
    'employee documents' => ['api.v1.hr.employee-documents.index', 'audit.access:EmployeeDocument,VIEWED'],
    'employee document download' => ['api.v1.hr.employee-documents.download', 'audit.access:EmployeeDocument,EXPORTED,download'],
    'dsar export' => ['settings.privacy.dsr.export', 'audit.access:DataSubjectRequest,EXPORTED,dsar'],
    'privacy request export' => ['api.v1.associations.privacy.requests.export', 'audit.access:DataSubjectRequest,EXPORTED,dsar'],
    'portability export' => ['settings.privacy.portability.export', 'audit.access:DataSubjectDossier,EXPORTED,portability,subjectId'],
]);

test('reading a bank account records a VIEWED event once per 60 seconds window', function (): void {
    $account = BankAccount::factory()->create(['tenant_id' => $this->tenant->id]);
    Sanctum::actingAs($this->owner);
    $client = $this->withHeader('X-Tenant-Id', (string) $this->tenant->id);

    $client->getJson('/api/v1/treasury/bank-accounts/'.$account->id)->assertOk();
    $client->getJson('/api/v1/treasury/bank-accounts/'.$account->id)->assertOk();

    expect(AuditLog::query()->where('event_type', 'VIEWED')->where('auditable_type', 'BankAccount')->where('auditable_id', $account->id)->count())->toBe(1);
});
