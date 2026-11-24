<?php

declare(strict_types=1);

use App\Actions\Purchases\RecordSupplierPayment;
use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Models\CostCenter;
use Modules\Treasury\Contracts\PaymentFactoryServiceInterface;
use Modules\Treasury\Models\BankAccount;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;

uses(RefreshDatabase::class);

function approvalMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function approvalActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

function approvalInvoice(Tenant $tenant, string $amount = '500.00', string $type = 'TD01'): PassiveInvoice
{
    $supplier = Contact::factory()->create(['tenant_id' => $tenant->id]);

    return PassiveInvoice::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'supplier_id' => $supplier->id,
        'invoice_number' => 'INV-'.Str::random(6),
        'date' => '2026-05-01',
        'due_date' => '2026-06-01',
        'total_amount' => $amount,
        'currency' => 'EUR',
        'document_type' => $type,
        'payment_status' => 'unpaid',
        'total_paid' => 0,
    ]);
}

function pendingStep(string $invoiceId): WorkflowInstanceStep
{
    $instance = WorkflowInstance::query()->where('approvable_id', $invoiceId)->latest('created_at')->firstOrFail();

    return WorkflowInstanceStep::query()->where('workflow_instance_id', $instance->id)->where('status', 'pending')->orderBy('tier_level')->firstOrFail();
}

function decideStep(WorkflowInstanceStep $step, string $decision = 'approved'): TestResponse
{
    return test()->postJson("/api/v1/workflows/steps/{$step->id}/decide", ['decision' => $decision]);
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->accountant = approvalMember($this->tenant, 'accountant');
    $this->secondAccountant = approvalMember($this->tenant, 'accountant');
    $this->owner = approvalMember($this->tenant, 'owner');
    $this->buyer = approvalMember($this->tenant, 'purchasing');

    approvalActAs($this->accountant, $this->tenant);

    $this->postJson('/api/v1/invoice-approvals/matrices', [
        'name' => 'General',
        'tiers' => [
            ['tier_level' => 1, 'approver_type' => 'role', 'approver_value' => 'accountant', 'threshold_min' => '100'],
            ['tier_level' => 2, 'approver_type' => 'role', 'approver_value' => 'owner', 'threshold_min' => '10000'],
        ],
    ])->assertCreated();
});

afterEach(fn () => app(TenantContext::class)->clear());

test('without an approval matrix an invoice is payable as before', function (): void {
    $invoice = approvalInvoice($this->other);

    expect($invoice->approval_status)->toBe('not_required')
        ->and($invoice->isPaymentBlocked())->toBeFalse();
});

test('an invoice waits for approval and cannot be paid by any route until approved', function (): void {
    $invoice = approvalInvoice($this->tenant);
    app(TenantContext::class)->setTenant($this->tenant);

    expect($invoice->fresh()->approval_status)->toBe('pending');

    expect(fn () => app(RecordSupplierPayment::class)->execute($invoice, ['amount' => 10, 'paid_at' => '2026-05-02']))->toThrow(ValidationException::class);

    $bank = BankAccount::factory()->create(['tenant_id' => $this->tenant->id, 'currency' => 'EUR']);

    expect(fn () => app(PaymentFactoryServiceInterface::class)->createBatch(
        ['tenant_id' => $this->tenant->id, 'bank_account_id' => $bank->id],
        [['amount' => 500, 'recipient_iban' => 'CH9300762011623852957', 'invoice_id' => $invoice->id]]
    ))->toThrow(InvalidArgumentException::class);

    app(TenantContext::class)->clear();
    approvalActAs($this->accountant, $this->tenant);
    $this->postJson("/api/v1/purchases/supplier-invoices/{$invoice->id}/mark-paid")->assertUnprocessable();
    $this->postJson('/api/v1/purchases/export-pain001', ['invoice_ids' => [$invoice->id], 'format' => 'pain.001.001.09'])->assertUnprocessable();
});

test('amount picks the levels: one level for a small invoice, then it can be paid', function (): void {
    $invoice = approvalInvoice($this->tenant, '500.00');

    approvalActAs($this->buyer, $this->tenant);
    $this->postJson("/api/v1/procurement/supplier-invoices/{$invoice->id}/submit-approval")->assertOk()->assertJsonPath('data.approval_status', 'in_approval');

    approvalActAs($this->accountant, $this->tenant);
    decideStep(pendingStep($invoice->id))->assertOk();

    expect($invoice->fresh()->approval_status)->toBe('approved')
        ->and($invoice->fresh()->isPaymentBlocked())->toBeFalse();

    app(TenantContext::class)->setTenant($this->tenant);
    $payment = app(RecordSupplierPayment::class)->execute($invoice, ['amount' => 500, 'paid_at' => '2026-05-02']);
    expect((float) $payment->amount)->toBe(500.0);
});

test('a large invoice runs its levels one after the other', function (): void {
    $invoice = approvalInvoice($this->tenant, '15000.00');

    approvalActAs($this->buyer, $this->tenant);
    $this->postJson("/api/v1/procurement/supplier-invoices/{$invoice->id}/submit-approval")->assertOk();

    $first = pendingStep($invoice->id);
    expect($first->assigned_role)->toBe('accountant')->and($first->tier_level)->toBe(1);

    approvalActAs($this->accountant, $this->tenant);
    decideStep($first)->assertOk();

    expect($invoice->fresh()->approval_status)->toBe('in_approval');

    $second = pendingStep($invoice->id);
    expect($second->assigned_role)->toBe('owner')->and($second->tier_level)->toBe(2);

    approvalActAs($this->owner, $this->tenant);
    decideStep($second)->assertOk();

    expect($invoice->fresh()->approval_status)->toBe('approved');
});

test('an amount below every threshold clears at submission', function (): void {
    $invoice = approvalInvoice($this->tenant, '50.00');

    approvalActAs($this->buyer, $this->tenant);
    $this->postJson("/api/v1/procurement/supplier-invoices/{$invoice->id}/submit-approval")->assertOk()->assertJsonPath('data.approval_status', 'not_required')->assertJsonPath('data.is_payment_blocked', false);
});

test('a cost centre matrix wins over the general one', function (): void {
    $costCenter = CostCenter::query()->create(['tenant_id' => $this->tenant->id, 'code' => 'R&D', 'name' => 'Research']);

    $this->postJson('/api/v1/invoice-approvals/matrices', [
        'name' => 'Research',
        'cost_center_id' => $costCenter->id,
        'tiers' => [['tier_level' => 1, 'approver_type' => 'role', 'approver_value' => 'owner']],
    ])->assertCreated();

    $invoice = approvalInvoice($this->tenant, '500.00');

    approvalActAs($this->buyer, $this->tenant);
    $this->postJson("/api/v1/procurement/supplier-invoices/{$invoice->id}/submit-approval", ['cost_center_id' => $costCenter->id])->assertOk();

    expect(pendingStep($invoice->id)->assigned_role)->toBe('owner');
});

test('four eyes: the submitter cannot approve, not even as owner, and nobody approves twice', function (): void {
    $invoice = approvalInvoice($this->tenant, '15000.00');

    approvalActAs($this->owner, $this->tenant);
    $this->postJson("/api/v1/procurement/supplier-invoices/{$invoice->id}/submit-approval")->assertOk();
    decideStep(pendingStep($invoice->id))->assertForbidden();

    approvalActAs($this->accountant, $this->tenant);
    decideStep(pendingStep($invoice->id))->assertOk();

    $second = pendingStep($invoice->id);
    approvalActAs($this->owner, $this->tenant);
    decideStep($second)->assertForbidden();

    expect($invoice->fresh()->approval_status)->toBe('in_approval');
});

test('the same person cannot sign two levels', function (): void {
    $invoice = approvalInvoice($this->tenant, '15000.00');
    $secondOwner = approvalMember($this->tenant, 'owner');

    approvalActAs($this->buyer, $this->tenant);
    $this->postJson("/api/v1/procurement/supplier-invoices/{$invoice->id}/submit-approval")->assertOk();

    approvalActAs($secondOwner, $this->tenant);
    decideStep(pendingStep($invoice->id))->assertOk();
    decideStep(pendingStep($invoice->id))->assertForbidden();
});

test('a rejection keeps the payment blocked and allows a new submission', function (): void {
    $invoice = approvalInvoice($this->tenant, '500.00');

    approvalActAs($this->buyer, $this->tenant);
    $this->postJson("/api/v1/procurement/supplier-invoices/{$invoice->id}/submit-approval")->assertOk();

    approvalActAs($this->accountant, $this->tenant);
    decideStep(pendingStep($invoice->id), 'rejected')->assertOk();

    expect($invoice->fresh()->approval_status)->toBe('rejected')
        ->and($invoice->fresh()->isPaymentBlocked())->toBeTrue();

    approvalActAs($this->buyer, $this->tenant);
    $this->postJson("/api/v1/procurement/supplier-invoices/{$invoice->id}/submit-approval")->assertOk()->assertJsonPath('data.approval_status', 'in_approval');
});

test('submitting twice while running is refused, and a decided invoice cannot restart', function (): void {
    $invoice = approvalInvoice($this->tenant, '500.00');

    approvalActAs($this->buyer, $this->tenant);
    $this->postJson("/api/v1/procurement/supplier-invoices/{$invoice->id}/submit-approval")->assertOk();
    $this->postJson("/api/v1/procurement/supplier-invoices/{$invoice->id}/submit-approval")->assertConflict();

    approvalActAs($this->accountant, $this->tenant);
    decideStep(pendingStep($invoice->id))->assertOk();
    $this->postJson("/api/v1/procurement/supplier-invoices/{$invoice->id}/submit-approval")->assertConflict();
});

test('cancelling the workflow does not open the payment', function (): void {
    $invoice = approvalInvoice($this->tenant, '500.00');

    approvalActAs($this->buyer, $this->tenant);
    $this->postJson("/api/v1/procurement/supplier-invoices/{$invoice->id}/submit-approval")->assertOk();

    app(TenantContext::class)->setTenant($this->tenant);
    app(WorkflowEngineInterface::class)->cancel(WorkflowInstance::query()->where('approvable_id', $invoice->id)->firstOrFail());

    expect($invoice->fresh()->approval_status)->toBe('pending');
});

test('matrices are limited to finance roles and approvers must belong to the tenant', function (): void {
    $this->postJson('/api/v1/invoice-approvals/matrices', [
        'name' => 'Bad',
        'tiers' => [['tier_level' => 1, 'approver_type' => 'user', 'approver_value' => approvalMember($this->other, 'admin')->id]],
    ])->assertUnprocessable();

    approvalActAs($this->buyer, $this->tenant);
    $this->getJson('/api/v1/invoice-approvals/matrices')->assertForbidden();
    $this->postJson('/api/v1/invoice-approvals/matrices', ['name' => 'X', 'tiers' => [['tier_level' => 1, 'approver_type' => 'role', 'approver_value' => 'member']]])->assertForbidden();
});

test('tenants cannot reach the invoices or rules of each other', function (): void {
    $invoice = approvalInvoice($this->tenant, '500.00');

    approvalActAs(approvalMember($this->other, 'admin'), $this->other);

    $this->getJson("/api/v1/procurement/supplier-invoices/{$invoice->id}/approval")->assertNotFound();
    $this->postJson("/api/v1/procurement/supplier-invoices/{$invoice->id}/submit-approval")->assertNotFound();
    $this->getJson('/api/v1/invoice-approvals/matrices')->assertOk()->assertJsonPath('data', []);
});
