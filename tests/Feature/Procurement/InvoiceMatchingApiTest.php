<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\InvoiceMatchingFixtures as Fixtures;

require_once __DIR__.'/InvoiceMatchingSupport.php';

uses(RefreshDatabase::class);

const MATCHING_API = '/api/v1/procurement/invoice-matching';

beforeEach(function (): void {
    matchingSetUp($this);
});

it('requires authentication', function (): void {
    $this->refreshApplication();

    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id)->getJson(MATCHING_API.'/runs')->assertUnauthorized();
});

it('runs the matching of an invoice and returns the line-by-line comparison', function (): void {
    $order = matchingReceivedOrder($this);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, [['Industrial Fan Unit', 10, '110.00'], ['Mounting Kit', 5, '20.00']]);

    $response = $this->postJson(MATCHING_API.'/run', ['passive_invoice_id' => (string) $invoice->id, 'purchase_order_id' => (string) $order->id])->assertCreated();

    $response->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'blocked_discrepancy')
        ->assertJsonPath('data.is_blocking', true)
        ->assertJsonPath('data.match_type', '3_way')
        ->assertJsonPath('data.po_number', $order->po_number)
        ->assertJsonPath('data.invoice_number', $invoice->invoice_number)
        ->assertJsonPath('data.discrepancy_count', 1)
        ->assertJsonCount(2, 'data.lines')
        ->assertJsonPath('data.lines.0.status', 'price_variance')
        ->assertJsonPath('data.lines.0.is_blocking', true)
        ->assertJsonPath('data.lines.0.ordered_unit_price', 100.0)
        ->assertJsonPath('data.lines.0.invoiced_unit_price', 110.0)
        ->assertJsonPath('data.lines.0.received_quantity', 10.0)
        ->assertJsonPath('data.lines.0.amount_variance', 100.0)
        ->assertJsonPath('data.lines.1.status', 'matched');
});

it('validates the invoice and the order of a run request', function (): void {
    $this->postJson(MATCHING_API.'/run', [])->assertUnprocessable()->assertJsonValidationErrors(['passive_invoice_id']);
    $this->postJson(MATCHING_API.'/run', ['passive_invoice_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['passive_invoice_id']);

    $invoice = Fixtures::invoice($this->tenant, $this->supplier, $this->poLines);
    $this->postJson(MATCHING_API.'/run', ['passive_invoice_id' => (string) $invoice->id, 'purchase_order_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['purchase_order_id']);
});

it('refuses an order of another supplier', function (): void {
    $other = Fixtures::order($this->tenant, Fixtures::supplier($this->tenant), $this->poLines);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, $this->poLines);

    $this->postJson(MATCHING_API.'/run', ['passive_invoice_id' => (string) $invoice->id, 'purchase_order_id' => (string) $other->id])->assertUnprocessable();
});

it('lists the current runs with filters and the blocked queue', function (): void {
    $blocked = matchingBlockedRun($this);
    $order = matchingReceivedOrder($this);
    $matched = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, $this->poLines), (string) $order->id);

    $this->getJson(MATCHING_API.'/runs')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson(MATCHING_API.'/runs?queue=blocked')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', (string) $blocked->id);
    $this->getJson(MATCHING_API.'/runs?status=matched')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', (string) $matched->id);
    $this->getJson(MATCHING_API.'/runs?match_type=2_way')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson(MATCHING_API.'/runs?search='.$blocked->invoice->invoice_number)->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson(MATCHING_API.'/runs?status=nonsense')->assertUnprocessable();
});

it('shows a run with its lines and resolution history, and the history of an invoice', function (): void {
    $run = matchingBlockedRun($this);
    $this->postJson(MATCHING_API."/runs/{$run->id}/request-credit-note", ['reason' => 'Wrong price'])->assertOk();

    $this->getJson(MATCHING_API."/runs/{$run->id}")->assertOk()
        ->assertJsonPath('data.status', 'pending_credit_note')
        ->assertJsonCount(2, 'data.lines')
        ->assertJsonCount(1, 'data.resolutions')
        ->assertJsonPath('data.resolutions.0.action', 'request_credit_note')
        ->assertJsonPath('data.resolutions.0.actor_name', $this->owner->name);

    $this->getJson(MATCHING_API."/invoices/{$run->passive_invoice_id}/history")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(MATCHING_API.'/runs/'.Str::uuid())->assertNotFound();
});

it('reports the dashboard figures', function (): void {
    matchingBlockedRun($this);
    $order = matchingReceivedOrder($this);
    matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, $this->poLines), (string) $order->id);

    $this->getJson(MATCHING_API.'/summary')->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.blocked_count', 1)
        ->assertJsonPath('data.blocked_amount', '1200.00')
        ->assertJsonPath('data.auto_match_rate', 50.0)
        ->assertJsonPath('data.by_status.matched', 1)
        ->assertJsonPath('data.by_status.blocked_discrepancy', 1)
        ->assertJsonPath('data.gr_ir_cleared_amount', '1100.00');
});

it('runs a batch', function (): void {
    $order = matchingReceivedOrder($this);
    Fixtures::invoice($this->tenant, $this->supplier, $this->poLines, ['qr_raw_payload' => (string) $order->po_number]);

    $this->postJson(MATCHING_API.'/batch-run', ['limit' => 10])->assertOk()
        ->assertJsonPath('data.processed', 1)
        ->assertJsonPath('data.matched', 1)
        ->assertJsonPath('data.blocked', 0);
    $this->postJson(MATCHING_API.'/batch-run', ['limit' => 1000])->assertUnprocessable()->assertJsonValidationErrors(['limit']);
});

it('walks a blocked invoice through credit note request, re-match and partial resolution', function (): void {
    $run = matchingBlockedRun($this);

    $this->postJson(MATCHING_API."/runs/{$run->id}/request-credit-note", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson(MATCHING_API."/runs/{$run->id}/request-credit-note", ['reason' => 'Wrong price', 'expected_credit_amount' => 100])->assertOk()->assertJsonPath('data.status', 'pending_credit_note');

    $rematched = $this->postJson(MATCHING_API."/runs/{$run->id}/rematch", [])->assertOk()->assertJsonPath('data.status', 'blocked_discrepancy')->json('data');
    expect($rematched['id'])->not->toBe((string) $run->id)->and($rematched['is_current'])->toBeTrue();

    $this->postJson(MATCHING_API."/runs/{$rematched['id']}/partial-resolution", ['reason' => 'Agreed', 'line_ids' => []])->assertUnprocessable()->assertJsonValidationErrors(['line_ids']);

    $newFan = collect($rematched['lines'])->firstWhere('line_number', 1);
    $this->postJson(MATCHING_API."/runs/{$rematched['id']}/partial-resolution", ['reason' => 'Price agreed', 'line_ids' => [(string) $newFan['id']]])
        ->assertOk()
        ->assertJsonPath('data.status', 'resolved')
        ->assertJsonPath('data.is_blocking', false);

});

it('reserves the override to owners, administrators and members granted the accounts payable approval', function (): void {
    $run = matchingBlockedRun($this);
    $payload = ['reason' => 'Price increase approved by the CFO'];

    $accountant = treasuryActor($this, 'accountant');
    $this->postJson(MATCHING_API."/runs/{$run->id}/override", $payload)->assertForbidden();
    expect($run->fresh()->status->value)->toBe('blocked_discrepancy');

    $cfo = User::create(['name' => 'CFO', 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $cfo->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'accountant', 'permissions' => json_encode(['accounts_payable_approval.write'])]);
    treasuryActAs($this, $cfo);

    $this->postJson(MATCHING_API."/runs/{$run->id}/override", ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson(MATCHING_API."/runs/{$run->id}/override", $payload)->assertOk()
        ->assertJsonPath('data.status', 'override_approved')
        ->assertJsonPath('data.is_blocking', false)
        ->assertJsonPath('data.resolutions.0.actor_name', 'CFO')
        ->assertJsonPath('data.resolutions.0.reason', $payload['reason']);

    expect($accountant->id)->not->toBe($cfo->id);
});

it('lets an owner override and refuses to override a run that is not blocked', function (): void {
    $run = matchingBlockedRun($this);

    $this->postJson(MATCHING_API."/runs/{$run->id}/override", ['reason' => 'Owner decision'])->assertOk();
    $this->postJson(MATCHING_API."/runs/{$run->id}/override", ['reason' => 'Owner decision again'])->assertUnprocessable();
});

it('manages the tolerance rules', function (): void {
    $payload = [
        'name' => 'Packaging 2 %',
        'scope' => 'item_category',
        'item_category' => 'packaging',
        'price_tolerance_percent' => 2,
        'price_tolerance_amount' => 5,
        'quantity_tolerance_percent' => 1,
        'currency' => 'chf',
    ];

    $id = $this->postJson(MATCHING_API.'/tolerance-rules', $payload)->assertCreated()
        ->assertJsonPath('data.scope', 'item_category')
        ->assertJsonPath('data.currency', 'CHF')
        ->assertJsonPath('data.price_tolerance_percent', 2.0)
        ->assertJsonPath('data.is_active', true)
        ->json('data.id');

    $this->postJson(MATCHING_API.'/tolerance-rules', ['name' => 'Supplier rule', 'scope' => 'supplier', 'price_tolerance_percent' => 1, 'price_tolerance_amount' => 0])
        ->assertUnprocessable()->assertJsonValidationErrors(['supplier_id']);
    $this->postJson(MATCHING_API.'/tolerance-rules', ['name' => 'Wide', 'scope' => 'tenant', 'price_tolerance_percent' => 101, 'price_tolerance_amount' => 0])
        ->assertUnprocessable()->assertJsonValidationErrors(['price_tolerance_percent']);

    $this->postJson(MATCHING_API.'/tolerance-rules', ['name' => 'Everything', 'scope' => 'tenant', 'price_tolerance_percent' => 0.5, 'price_tolerance_amount' => 1, 'item_category' => 'ignored'])->assertCreated()
        ->assertJsonPath('data.item_category', null);

    $this->getJson(MATCHING_API.'/tolerance-rules')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.scope', 'item_category');

    $this->putJson(MATCHING_API."/tolerance-rules/{$id}", array_merge($payload, ['name' => 'Packaging 3 %', 'price_tolerance_percent' => 3, 'is_active' => false]))->assertOk()
        ->assertJsonPath('data.name', 'Packaging 3 %')
        ->assertJsonPath('data.is_active', false);

    $this->deleteJson(MATCHING_API."/tolerance-rules/{$id}")->assertOk();
    $this->getJson(MATCHING_API.'/tolerance-rules')->assertJsonCount(1, 'data');
    $this->deleteJson(MATCHING_API."/tolerance-rules/{$id}")->assertNotFound();
});

it('applies the tolerance rule created through the API to the next matching run', function (): void {
    $this->postJson(MATCHING_API.'/tolerance-rules', ['name' => 'Global 2 %', 'scope' => 'tenant', 'price_tolerance_percent' => 2, 'price_tolerance_amount' => 0])->assertCreated();
    $order = matchingReceivedOrder($this);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, [['Industrial Fan Unit', 10, '101.50'], ['Mounting Kit', 5, '20.00']]);

    $this->postJson(MATCHING_API.'/run', ['passive_invoice_id' => (string) $invoice->id, 'purchase_order_id' => (string) $order->id])->assertCreated()
        ->assertJsonPath('data.status', 'within_tolerance')
        ->assertJsonPath('data.tolerance_snapshot.0.price_tolerance_percent', '2.00');
});

it('keeps the runs and rules of a company away from the others', function (): void {
    $run = matchingBlockedRun($this);
    $this->postJson(MATCHING_API.'/tolerance-rules', ['name' => 'Mine', 'scope' => 'tenant', 'price_tolerance_percent' => 1, 'price_tolerance_amount' => 0])->assertCreated();

    $outsider = treasuryActor($this, 'owner', $this->otherTenant);

    $this->getJson(MATCHING_API."/runs/{$run->id}")->assertNotFound();
    $this->getJson(MATCHING_API.'/runs')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson(MATCHING_API.'/tolerance-rules')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson(MATCHING_API."/runs/{$run->id}/override", ['reason' => 'Cross-company attempt'])->assertNotFound();

    expect($outsider->id)->not->toBe($this->owner->id);
});

it('shows the GR/IR accrual of the goods received and what the matched invoice clears', function (): void {
    $order = matchingReceivedOrder($this);
    $run = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, $this->poLines), (string) $order->id);

    $this->getJson(MATCHING_API."/runs/{$run->id}/gr-ir")->assertOk()
        ->assertJsonPath('data.expected_clearing_amount', '1100.00')
        ->assertJsonPath('data.expected_variance_amount', '0.00')
        ->assertJsonPath('data.receipts', [])
        ->assertJsonPath('data.invoice', null);
});
