<?php

declare(strict_types=1);

use App\Models\PassiveInvoice;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Intercompany\Models\SyncEvent;
use Modules\Intercompany\Models\TransactionLink;

require_once __DIR__.'/IntercompanySupport.php';

uses(RefreshDatabase::class);

/**
 * Payment synchronisation (partial and full settlement, variance within the tolerance, idempotence) and the netting of the
 * open items of two entities that invoice each other.
 */
beforeEach(function (): void {
    icGroupSetUp($this);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('a partial payment of the source invoice is carried to the mirror', function (): void {
    $invoice = icInvoice($this->parent, $this->italiaAsCustomer);
    $transaction = icScan($this)['transactions'][0];

    icPay($invoice, '500.00');

    $this->postJson("/api/v1/intercompany/transactions/{$transaction['id']}/sync-payment", [])
        ->assertOk()
        ->assertJsonPath('data.payment_status', 'partial')
        ->assertJsonPath('data.synced_paid_amount', '500.0000');

    $mirror = PassiveInvoice::withoutGlobalScopes()->findOrFail($transaction['mirror_document_id']);
    expect((string) $mirror->total_paid)->toBe('500.00')->and($mirror->payment_status)->toBe('partial');
});

test('a full payment settles the mirror and a small shortfall is kept as a variance', function (): void {
    $invoice = icInvoice($this->parent, $this->italiaAsCustomer);
    $transaction = icScan($this)['transactions'][0];

    // 0.50 below the amount, within the tolerance of 1.00 of the group: the mirror closes and the difference is logged.
    icPay($invoice, '1219.50');

    $this->postJson("/api/v1/intercompany/transactions/{$transaction['id']}/sync-payment", [])
        ->assertOk()
        ->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonPath('data.paid_variance', '0.5000');

    expect(PassiveInvoice::withoutGlobalScopes()->findOrFail($transaction['mirror_document_id'])->payment_status)->toBe('paid')
        ->and(SyncEvent::withoutGlobalScopes()->where('transaction_id', $transaction['id'])->where('event', 'payment_variance')->count())->toBe(1);
});

test('synchronising the same payment twice changes nothing', function (): void {
    $invoice = icInvoice($this->parent, $this->italiaAsCustomer);
    $transaction = icScan($this)['transactions'][0];
    icPay($invoice, '300.00');

    $this->postJson("/api/v1/intercompany/transactions/{$transaction['id']}/sync-payment", [])->assertOk();
    $version = TransactionLink::withoutGlobalScopes()->findOrFail($transaction['id'])->version;
    $this->postJson("/api/v1/intercompany/transactions/{$transaction['id']}/sync-payment", [])->assertOk();

    expect(TransactionLink::withoutGlobalScopes()->findOrFail($transaction['id'])->version)->toBe($version)
        ->and(SyncEvent::withoutGlobalScopes()->where('transaction_id', $transaction['id'])->where('event', 'payment_synced')->count())->toBe(1);
});

test('a payment cannot be synchronised before the mirror exists', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer);
    $transaction = icScan($this)['transactions'][0];
    $this->postJson("/api/v1/intercompany/transactions/{$transaction['id']}/reverse", ['reason' => 'Issued by mistake'])->assertOk();

    $this->postJson("/api/v1/intercompany/transactions/{$transaction['id']}/sync-payment", [])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'intercompany.error.mirror_not_available');
});

test('netting offsets what two entities owe each other and settles both sides', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'A-1', 'date' => '2027-03-01']);
    icInvoice($this->italia, $this->parentAsCustomer, ['invoice_number' => 'B-1', 'date' => '2027-03-05', 'total_net' => 400, 'total_tax' => 88, 'total_gross' => 488, 'receivable_amount' => 488, 'subtotal' => 400]);
    $scan = icScan($this);
    expect($scan['created'])->toBe(2);

    $proposal = $this->postJson("/api/v1/intercompany/groups/{$this->group->id}/netting", [
        'entity_a_tenant_id' => $this->parent->id,
        'entity_b_tenant_id' => $this->italia->id,
        'as_of_date' => '2027-03-31',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.receivable_total', '1220.0000')
        ->assertJsonPath('data.payable_total', '488.0000')
        ->assertJsonPath('data.net_amount', '732.0000')
        ->assertJsonPath('data.net_direction', 'b_pays_a')
        ->json('data');

    expect($proposal['items'])->toHaveCount(2);

    $this->postJson("/api/v1/intercompany/netting/{$proposal['id']}/confirm", ['expected_version' => $proposal['version']])
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed');

    $byNumber = TransactionLink::withoutGlobalScopes()->get()->keyBy('source_document_number');

    expect($byNumber['B-1']->payment_status)->toBe('paid')
        ->and((string) $byNumber['B-1']->synced_paid_amount)->toBe('488.0000')
        ->and($byNumber['A-1']->payment_status)->toBe('partial')
        ->and((string) $byNumber['A-1']->synced_paid_amount)->toBe('488.0000')
        ->and(SyncEvent::withoutGlobalScopes()->where('event', 'netting_applied')->count())->toBe(2);

    // A netting that was applied cannot be applied or cancelled again.
    $this->postJson("/api/v1/intercompany/netting/{$proposal['id']}/confirm", [])->assertStatus(409)->assertJsonPath('error_code', 'intercompany.error.netting_not_draft');
    $this->postJson("/api/v1/intercompany/netting/{$proposal['id']}/cancel", ['reason' => 'Too late'])->assertStatus(409);
});

test('a cancelled netting changes no document', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'A-1']);
    icInvoice($this->italia, $this->parentAsCustomer, ['invoice_number' => 'B-1']);
    icScan($this);

    $proposal = $this->postJson("/api/v1/intercompany/groups/{$this->group->id}/netting", [
        'entity_a_tenant_id' => $this->parent->id,
        'entity_b_tenant_id' => $this->italia->id,
        'as_of_date' => '2027-03-31',
    ])->assertCreated()->assertJsonPath('data.net_direction', 'balanced')->json('data');

    $this->postJson("/api/v1/intercompany/netting/{$proposal['id']}/cancel", ['reason' => 'Not agreed with the treasury'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect(TransactionLink::withoutGlobalScopes()->where('payment_status', '!=', 'unpaid')->count())->toBe(0);
});

test('netting is refused when it is switched off or when the entities are the same', function (): void {
    $this->postJson("/api/v1/intercompany/groups/{$this->group->id}/netting", [
        'entity_a_tenant_id' => $this->parent->id,
        'entity_b_tenant_id' => $this->parent->id,
        'as_of_date' => '2027-03-31',
    ])->assertStatus(422);

    $this->putJson("/api/v1/intercompany/groups/{$this->group->id}/settings", [
        'mirror_mode' => 'draft', 'auto_mirror' => true, 'sync_payments' => true, 'netting_enabled' => false,
        'amount_tolerance' => 1, 'date_tolerance_days' => 5, 'default_standard' => 'fer30',
    ])->assertOk();

    $this->postJson("/api/v1/intercompany/groups/{$this->group->id}/netting", [
        'entity_a_tenant_id' => $this->parent->id,
        'entity_b_tenant_id' => $this->italia->id,
        'as_of_date' => '2027-03-31',
    ])->assertStatus(422)->assertJsonPath('error_code', 'intercompany.error.netting_disabled');
});
