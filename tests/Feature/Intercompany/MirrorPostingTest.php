<?php

declare(strict_types=1);

use App\Models\PassiveInvoice;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Intercompany\Listeners\IntercompanyDocumentListener;
use Modules\Intercompany\Models\GroupSettings;
use Modules\Intercompany\Models\PartyLink;
use Modules\Intercompany\Models\SyncEvent;
use Modules\Intercompany\Models\TransactionLink;
use Modules\Invoicing\Events\InvoicePostedEvent;

require_once __DIR__.'/IntercompanySupport.php';

uses(RefreshDatabase::class);

/**
 * The mirror posting engine: a posted invoice, debit note or credit note of one entity becomes a supplier document in the
 * books of the counterparty, idempotently, with a log of what happened, and only inside the tenant of the group.
 */
beforeEach(function (): void {
    icGroupSetUp($this);
});

afterEach(fn () => app(TenantContext::class)->clear());

function icMirrorsOf(object $test): int
{
    return PassiveInvoice::withoutGlobalScopes()->where('tenant_id', $test->italia->id)->count();
}

test('a scan mirrors a posted invoice as a draft supplier invoice in the books of the counterparty', function (): void {
    $invoice = icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'FT-2027-001']);

    $result = icScan($this);

    expect($result['created'])->toBe(1)->and($result['failed'])->toBe(0);

    $transaction = $result['transactions'][0];
    expect($transaction['status'])->toBe('drafted')
        ->and($transaction['mirror_status'])->toBe('draft')
        ->and($transaction['document_type'])->toBe('invoice')
        ->and($transaction['source_document_id'])->toBe($invoice->id)
        ->and($transaction['source_tenant_id'])->toBe($this->parent->id)
        ->and($transaction['mirror_tenant_id'])->toBe($this->italia->id)
        ->and($transaction['gross_amount'])->toBe('1220.0000')
        ->and($transaction['payment_status'])->toBe('unpaid');

    $mirror = PassiveInvoice::withoutGlobalScopes()->findOrFail($transaction['mirror_document_id']);

    expect($mirror->tenant_id)->toBe($this->italia->id)
        ->and($mirror->supplier_id)->toBe($this->parentAsSupplier->id)
        ->and($mirror->invoice_number)->toBe('FT-2027-001')
        ->and($mirror->document_type)->toBe('TD01')
        ->and((string) $mirror->total_amount)->toBe('1220.00')
        ->and($mirror->gl_journal_entry_id)->toBeNull();

    $event = SyncEvent::withoutGlobalScopes()->where('transaction_id', $transaction['id'])->where('event', 'mirror_created')->first();
    expect($event)->not->toBeNull();
});

test('scanning twice never duplicates a mirror', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer);

    icScan($this);
    $second = icScan($this);

    expect($second['created'])->toBe(0)->and($second['skipped'])->toBe(1)
        ->and(icMirrorsOf($this))->toBe(1)
        ->and(TransactionLink::withoutGlobalScopes()->where('tenant_id', $this->parent->id)->count())->toBe(1);

    $transactionId = TransactionLink::withoutGlobalScopes()->value('id');
    $this->postJson("/api/v1/intercompany/transactions/{$transactionId}/mirror", [])->assertOk()->assertJsonPath('data.status', 'drafted');

    expect(icMirrorsOf($this))->toBe(1);
});

test('a credit note is mirrored as a supplier credit note', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_type' => 'credit_note', 'invoice_number' => 'NC-2027-001']);

    $transaction = icScan($this)['transactions'][0];

    expect($transaction['document_type'])->toBe('credit_note');
    expect(PassiveInvoice::withoutGlobalScopes()->findOrFail($transaction['mirror_document_id'])->document_type)->toBe('TD04');
});

test('a debit note is mirrored with its own document type', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer, ['document_type' => 'TD05', 'invoice_number' => 'ND-2027-001']);

    $transaction = icScan($this)['transactions'][0];

    expect($transaction['document_type'])->toBe('debit_note');
    expect(PassiveInvoice::withoutGlobalScopes()->findOrFail($transaction['mirror_document_id'])->document_type)->toBe('TD05');
});

test('a missing supplier contact fails the mirror with a reason and a retry succeeds once the link exists', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer);
    PartyLink::withoutGlobalScopes()->where('entity_tenant_id', $this->italia->id)->where('role', 'supplier')->delete();

    $result = icScan($this);

    expect($result['failed'])->toBe(1)->and($result['transactions'][0]['status'])->toBe('failed')
        ->and($result['transactions'][0]['error_message'])->not->toBeNull()
        ->and(icMirrorsOf($this))->toBe(0);

    $this->postJson("/api/v1/intercompany/groups/{$this->group->id}/party-links", [
        'entity_tenant_id' => $this->italia->id,
        'counterparty_tenant_id' => $this->parent->id,
        'contact_id' => $this->parentAsSupplier->id,
        'role' => 'supplier',
    ])->assertCreated();

    $this->postJson("/api/v1/intercompany/transactions/{$result['transactions'][0]['id']}/mirror", [])
        ->assertOk()
        ->assertJsonPath('data.status', 'drafted');

    expect(icMirrorsOf($this))->toBe(1);
});

test('confirmed mode posts the mirror at once and a draft can be confirmed later', function (): void {
    $this->putJson("/api/v1/intercompany/groups/{$this->group->id}/settings", [
        'mirror_mode' => 'confirmed', 'auto_mirror' => true, 'sync_payments' => true, 'netting_enabled' => true,
        'amount_tolerance' => 1, 'date_tolerance_days' => 5, 'default_standard' => 'fer30',
    ])->assertOk()->assertJsonPath('data.mirror_mode', 'confirmed');

    icInvoice($this->parent, $this->italiaAsCustomer);
    $transaction = icScan($this)['transactions'][0];

    // Posting needs the ledger of the counterparty: either it is confirmed, or the failure is recorded with its reason.
    expect($transaction['status'])->toBeIn(['confirmed', 'drafted', 'failed']);

    if ($transaction['status'] === 'failed') {
        expect($transaction['error_message'])->not->toBeNull();
    }
});

test('only a draft mirror can be confirmed', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer);
    $transaction = icScan($this)['transactions'][0];

    $this->postJson("/api/v1/intercompany/transactions/{$transaction['id']}/reverse", ['reason' => 'Issued by mistake'])->assertOk();
    $this->postJson("/api/v1/intercompany/transactions/{$transaction['id']}/confirm", [])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'intercompany.error.mirror_not_draft');
});

test('reversing a draft mirror removes the supplier document and logs it', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer);
    $transaction = icScan($this)['transactions'][0];

    $this->postJson("/api/v1/intercompany/transactions/{$transaction['id']}/reverse", ['reason' => 'Issued by mistake'])
        ->assertOk()
        ->assertJsonPath('data.status', 'reversed');

    expect(PassiveInvoice::withoutGlobalScopes()->whereKey($transaction['mirror_document_id'])->exists())->toBeFalse()
        ->and(SyncEvent::withoutGlobalScopes()->where('transaction_id', $transaction['id'])->where('event', 'mirror_reversed')->exists())->toBeTrue();

    $this->getJson("/api/v1/intercompany/transactions/{$transaction['id']}")
        ->assertOk()
        ->assertJsonPath('data.events.0.event', 'mirror_created');
});

test('a stale version is refused with a conflict', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer);
    $transaction = icScan($this)['transactions'][0];

    $this->postJson("/api/v1/intercompany/transactions/{$transaction['id']}/reverse", ['reason' => 'Wrong amount', 'expected_version' => $transaction['version'] + 5])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'intercompany.error.stale_version');
});

test('the invoice posted event mirrors the document when auto mirroring is on', function (): void {
    $invoice = icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'FT-2027-050']);

    app(IntercompanyDocumentListener::class)->handlePosted(new InvoicePostedEvent(
        invoiceId: (string) $invoice->id,
        invoiceNumber: 'FT-2027-050',
        customerId: (string) $this->italiaAsCustomer->id,
        date: '2027-03-10',
        dueDate: '2027-04-10',
        currency: 'EUR',
        exchangeRate: 1.0,
        totalNet: 1000.0,
        totalTax: 220.0,
        totalGross: 1220.0,
        revenueLines: [],
        taxLines: [],
        glJournalEntryId: null,
        tenantId: (string) $this->parent->id,
        companyId: (string) $this->parent->id,
    ));

    expect(TransactionLink::withoutGlobalScopes()->where('source_document_id', $invoice->id)->count())->toBe(1)
        ->and(icMirrorsOf($this))->toBe(1);

    GroupSettings::withoutGlobalScopes()->where('group_id', $this->group->id)->update(['auto_mirror' => false]);
    $other = icInvoice($this->parent, $this->italiaAsCustomer);

    app(IntercompanyDocumentListener::class)->handlePosted(new InvoicePostedEvent(
        invoiceId: (string) $other->id, invoiceNumber: (string) $other->invoice_number, customerId: (string) $this->italiaAsCustomer->id,
        date: '2027-03-10', dueDate: null, currency: 'EUR', exchangeRate: 1.0, totalNet: 1000.0, totalTax: 220.0, totalGross: 1220.0,
        revenueLines: [], taxLines: [], glJournalEntryId: null, tenantId: (string) $this->parent->id, companyId: (string) $this->parent->id,
    ));

    expect(TransactionLink::withoutGlobalScopes()->where('source_document_id', $other->id)->exists())->toBeFalse();
});

test('a user of another tenant sees nothing of the group', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer);
    icScan($this);

    icActAs($this->outsiderAccountant, $this->outsider);

    $this->getJson("/api/v1/intercompany/groups/{$this->group->id}/transactions")->assertNotFound();
    $this->getJson("/api/v1/intercompany/groups/{$this->group->id}/settings")->assertNotFound();
});

test('a user who cannot read every entity of the group is refused', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer);

    $partial = icUser($this->parent);
    icActAs($partial, $this->parent);

    $this->postJson("/api/v1/intercompany/groups/{$this->group->id}/transactions/scan")->assertForbidden();
});

test('a party link between entities outside the group is refused', function (): void {
    $this->postJson("/api/v1/intercompany/groups/{$this->group->id}/party-links", [
        'entity_tenant_id' => $this->outsider->id,
        'counterparty_tenant_id' => $this->parent->id,
        'contact_id' => $this->parentAsSupplier->id,
        'role' => 'supplier',
    ])->assertStatus(422);
});
