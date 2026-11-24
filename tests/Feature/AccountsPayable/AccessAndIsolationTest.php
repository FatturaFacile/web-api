<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\AccountsPayable\Models\ApOpenItem;
use Modules\AccountsPayable\Models\ApPaymentRun;
use Modules\AccountsPayable\Models\ApSupplierTerm;

require_once __DIR__.'/AccountsPayableSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    apSetUp($this);
});

it('requires authentication', function (): void {
    // A fresh application has no signed-in user: authentication is the first thing the routes ask for.
    $this->refreshApplication();

    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id)->getJson('/api/v1/accounts-payable/open-items')->assertUnauthorized();
});

it('lets the accounting roles read and closes the module to the other members', function (string $role, int $status): void {
    treasuryActor($this, $role);

    foreach (['open-items', 'payment-runs', 'supplier-terms', 'aging'] as $path) {
        $this->getJson("/api/v1/accounts-payable/{$path}")->assertStatus($status);
    }
})->with([
    'owner' => ['owner', 200],
    'admin' => ['admin', 200],
    'accountant' => ['accountant', 200],
    'read-only accountant' => ['accountant_readonly', 200],
    'sales' => ['sales', 403],
    'plain member' => ['member', 403],
]);

it('lets the read-only accountant read and download but not change or simulate', function (): void {
    $invoice = apInvoice($this, $this->supplier, 'RO-1', '500.00');
    apSync($this);
    $id = apItem($invoice)->id;

    treasuryActor($this, 'accountant_readonly');

    $this->getJson("/api/v1/accounts-payable/open-items/{$id}")->assertOk();
    $this->getJson('/api/v1/accounts-payable/aging/export/xlsx')->assertOk();
    $this->getJson('/api/v1/accounts-payable/aging/export/pdf')->assertOk();

    // The tenant middleware refuses every non-safe method to this role.
    $this->postJson("/api/v1/accounts-payable/open-items/{$id}/dispute", ['reason' => 'No'])->assertForbidden();
    $this->postJson("/api/v1/accounts-payable/open-items/{$id}/release", ['reason' => 'No'])->assertForbidden();
    $this->putJson("/api/v1/accounts-payable/open-items/{$id}/skonto-terms", ['skonto_percentage' => 2, 'skonto_days' => 10])->assertForbidden();
    $this->postJson('/api/v1/accounts-payable/open-items', [])->assertForbidden();
    $this->postJson('/api/v1/accounts-payable/supplier-terms', ['supplier_id' => $this->supplier->id, 'skonto_percentage' => 2, 'skonto_days' => 10])->assertForbidden();
    $this->postJson('/api/v1/accounts-payable/payment-runs', apRunPayload())->assertForbidden();

    expect(ApOpenItem::withoutGlobalScopes()->findOrFail($id)->status)->toBe('open');
});

it('lets a member with the read grant run the simulations but not write', function (): void {
    apInvoice($this, $this->supplier, 'GR-1', '500.00');
    $member = User::create(['name' => 'grant', 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $member->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member', 'permissions' => json_encode(['accounts_payable.read'])]);
    treasuryActAs($this, $member);

    $this->getJson('/api/v1/accounts-payable/open-items')->assertOk();
    $this->postJson('/api/v1/accounts-payable/payment-runs/preview', apRunPayload())->assertOk()->assertJsonPath('data.totals.items_count', 1);
    $this->postJson('/api/v1/accounts-payable/supplier-terms/parse', ['clause' => '2% skonto entro 10 gg'])->assertOk();
    $this->postJson('/api/v1/accounts-payable/payment-runs', apRunPayload())->assertForbidden();
    $this->postJson('/api/v1/accounts-payable/open-items', [])->assertForbidden();
});

it('reserves the approval of a payment run to the CFO: owners, administrators or a member granted the permission', function (): void {
    apInvoice($this, $this->supplier, 'CFO-1', '500.00');
    $runId = $this->postJson('/api/v1/accounts-payable/payment-runs', apRunPayload())->assertCreated()->json('data.id');
    $this->postJson("/api/v1/accounts-payable/payment-runs/{$runId}/submit")->assertOk();
    $base = "/api/v1/accounts-payable/payment-runs/{$runId}";

    // Accountants prepare and execute, they do not decide.
    $this->postJson("{$base}/approve")->assertForbidden();
    $this->postJson("{$base}/reject", ['reason' => 'No'])->assertForbidden();
    treasuryActor($this, 'accountant_readonly');
    $this->postJson("{$base}/approve")->assertForbidden();

    $member = User::create(['name' => 'CFO', 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $member->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member', 'permissions' => json_encode(['accounts_payable_approval.write'])]);
    treasuryActAs($this, $member);

    $this->postJson("{$base}/approve")->assertOk()->assertJsonPath('data.status', 'authorized')->assertJsonPath('data.workflow.approved_by', $member->id);
});

it('keeps the open items, runs and agreements of two companies apart', function (): void {
    $mine = apInvoice($this, $this->supplier, 'MINE-1', '500.00');
    apSync($this);
    $myItem = apItem($mine)->id;
    $preview = $this->postJson('/api/v1/accounts-payable/payment-runs/preview', apRunPayload(['cutoff_date' => now()->addDays(30)->toDateString()]))->assertOk()->json('data.items');
    $myRun = $this->postJson('/api/v1/accounts-payable/payment-runs', apRunPayload())->assertCreated()->json('data.id');
    $myTerm = $this->postJson('/api/v1/accounts-payable/supplier-terms', ['supplier_id' => $this->supplier->id, 'skonto_percentage' => 2, 'skonto_days' => 10])->assertCreated()->json('data.id');

    $foreignSupplier = treasuryInTenant($this->otherTenant, fn () => apSupplier($this->otherTenant, 'Fornitore Altrui', 'IT84S0542811101000000654321'));
    $foreignItem = treasuryInTenant($this->otherTenant, fn (): ApOpenItem => ApOpenItem::withoutGlobalScopes()->create([
        'tenant_id' => $this->otherTenant->id, 'supplier_id' => $foreignSupplier->id, 'source' => 'manual', 'kind' => 'invoice', 'document_number' => 'THEIRS-1', 'document_date' => now()->toDateString(),
        'due_date' => now()->addDays(2)->toDateString(), 'currency' => 'EUR', 'original_amount' => '999.00', 'paid_amount' => '0.00', 'open_amount' => '999.00', 'creditor_iban' => 'IT84S0542811101000000654321', 'status' => 'open',
    ]));
    $foreignRun = treasuryInTenant($this->otherTenant, fn (): ApPaymentRun => ApPaymentRun::withoutGlobalScopes()->create([
        'tenant_id' => $this->otherTenant->id, 'run_number' => 'DP-THEIRS', 'status' => 'pending_cfo_approval', 'currency' => 'EUR', 'cutoff_date' => now()->toDateString(), 'execution_date' => now()->toDateString(),
    ]));
    $foreignTerm = treasuryInTenant($this->otherTenant, fn (): ApSupplierTerm => ApSupplierTerm::withoutGlobalScopes()->create([
        'tenant_id' => $this->otherTenant->id, 'supplier_id' => $foreignSupplier->id, 'skonto_percentage' => '5.00', 'skonto_days' => 10, 'is_active' => true,
    ]));
    treasuryInTenant($this->otherTenant, fn () => treasurySupplierInvoice($this->otherTenant, $foreignSupplier, 'THEIRS-2', '888.00'));

    // Seen from the first company: nothing of the other one.
    expect(collect($this->getJson('/api/v1/accounts-payable/open-items')->assertOk()->json('data'))->pluck('document_number')->all())->toBe(['MINE-1'])
        ->and(collect($this->getJson('/api/v1/accounts-payable/payment-runs')->assertOk()->json('data'))->pluck('id')->all())->toBe([$myRun])
        ->and(collect($this->getJson('/api/v1/accounts-payable/supplier-terms')->assertOk()->json('data'))->pluck('id')->all())->toBe([$myTerm])
        ->and($this->getJson('/api/v1/accounts-payable/aging')->assertOk()->json('data.summary.total_open'))->toBe('500.00')
        ->and(collect($preview)->pluck('document_number')->all())->toBe(['MINE-1']);

    $this->getJson("/api/v1/accounts-payable/open-items/{$foreignItem->id}")->assertNotFound();
    $this->getJson("/api/v1/accounts-payable/open-items/{$foreignItem->id}/history")->assertNotFound();
    $this->postJson("/api/v1/accounts-payable/open-items/{$foreignItem->id}/dispute", ['reason' => 'Hijack'])->assertNotFound();
    $this->putJson("/api/v1/accounts-payable/open-items/{$foreignItem->id}/skonto-terms", ['skonto_percentage' => 2, 'skonto_days' => 10])->assertNotFound();
    $this->getJson("/api/v1/accounts-payable/payment-runs/{$foreignRun->id}")->assertNotFound();
    $this->postJson("/api/v1/accounts-payable/payment-runs/{$foreignRun->id}/cancel")->assertNotFound();
    $this->putJson("/api/v1/accounts-payable/supplier-terms/{$foreignTerm->id}", ['skonto_percentage' => 1])->assertNotFound();
    $this->deleteJson("/api/v1/accounts-payable/supplier-terms/{$foreignTerm->id}")->assertNotFound();
    $this->postJson('/api/v1/accounts-payable/supplier-terms', ['supplier_id' => $foreignSupplier->id, 'skonto_percentage' => 2, 'skonto_days' => 10])->assertUnprocessable()->assertJsonValidationErrors(['supplier_id']);
    $this->postJson('/api/v1/accounts-payable/open-items', ['supplier_id' => $foreignSupplier->id, 'document_number' => 'X', 'document_date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'currency' => 'EUR', 'amount' => 10])->assertUnprocessable()->assertJsonValidationErrors(['supplier_id']);

    // The administrator of the other company cannot approve the run of the first one.
    treasuryActor($this, 'admin', $this->otherTenant);
    $this->postJson("/api/v1/accounts-payable/payment-runs/{$myRun}/approve")->assertNotFound();
    $this->getJson("/api/v1/accounts-payable/open-items/{$myItem}")->assertNotFound();
});
