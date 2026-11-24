<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/AccountsPayableSupport.php';

uses(RefreshDatabase::class);

const PAYABLES = '/api/v1/accounting/payables';

/**
 * The contract of the web client (`/api/v1/accounting/payables`): items, batches and aging, drawn from the same services as
 * `/api/v1/accounts-payable`. Alfa (IBAN X): A-1 1000 due in 5 days, A-2 500 due in 3 days, A-3 300 due in 60 days, a credit
 * note of 200. Beta (IBAN Y, 2% within 10 days): B-1 500 of 3 days ago, due in 57 days. A QR bill of Gamma.
 */
beforeEach(function (): void {
    apSetUp($this);
    $this->beta = apSupplier($this->tenant, 'Fornitore Beta SPA', 'IT84S0542811101000000654321');
    $this->postJson('/api/v1/accounts-payable/supplier-terms', ['supplier_id' => $this->beta->id, 'skonto_percentage' => 2, 'skonto_days' => 10, 'net_days' => 60])->assertCreated();

    $this->a1 = apInvoice($this, $this->supplier, 'A-1', '1000.00', 5);
    $this->a2 = apInvoice($this, $this->supplier, 'A-2', '500.00', 3);
    $this->a3 = apInvoice($this, $this->supplier, 'A-3', '300.00', 60);
    $this->credit = apCreditNote($this, $this->supplier, 'A-NC', '200.00', 0);
    $this->b1 = apInvoice($this, $this->beta, 'B-1', '500.00', 57, ['date' => now()->subDays(3)->toDateString()]);
    $this->gamma = apSupplier($this->tenant, 'Gamma AG', 'CH4431999123000889012');
    $this->qr = apInvoice($this, $this->gamma, 'G-QR', '120.00', 8, ['qr_raw_payload' => "SPC\n0200\n1\nCH4431999123000889012\nS\nGamma AG", 'currency' => 'EUR']);
    $this->admin = treasuryActor($this, 'admin');
    treasuryActAs($this, $this->accountant);
});

function payablesBatchPayload(array $overrides = []): array
{
    return array_merge(['cutoff_date' => now()->addDays(10)->toDateString(), 'prioritize_skonto' => true], $overrides);
}

function payablesItems(object $test, string $query = ''): array
{
    return $test->getJson(PAYABLES.'/items'.($query === '' ? '' : '?'.$query))->assertOk()->json();
}

function payablesItem(object $test, string $number): array
{
    return collect(payablesItems($test, 'per_page=100')['data'])->firstWhere('document_number', $number);
}

it('lists the items in the shape of the client with the Laravel pagination', function (): void {
    $page = payablesItems($this, 'per_page=3');

    expect($page['data'])->toHaveCount(3)
        ->and($page['meta'])->toMatchArray(['current_page' => 1, 'per_page' => 3, 'total' => 6, 'last_page' => 2])
        ->and($page)->toHaveKey('links');

    $item = payablesItem($this, 'A-1');

    expect(array_keys($item))->toBe(['id', 'supplier_id', 'supplier_name', 'supplier_iban', 'document_type', 'document_number', 'issue_date', 'due_date', 'currency', 'original_amount', 'open_amount', 'status', 'block_reason', 'batch_id', 'skonto'])
        ->and($item)->toMatchArray([
            'supplier_id' => $this->supplier->id, 'supplier_name' => 'Fornitore Alfa SRL', 'supplier_iban' => 'IT60X0542811101000000123456', 'document_type' => 'xml_invoice',
            'issue_date' => now()->subDays(10)->toDateString(), 'due_date' => now()->addDays(5)->toDateString(), 'currency' => 'EUR', 'original_amount' => '1000.00', 'open_amount' => '1000.00',
            'status' => 'open', 'block_reason' => null, 'batch_id' => null, 'skonto' => null,
        ])
        ->and(payablesItem($this, 'G-QR')['document_type'])->toBe('qr_bill')
        ->and(payablesItem($this, 'A-NC')['document_type'])->toBe('credit_note')
        ->and(payablesItem($this, 'A-NC')['skonto'])->toBeNull();

    $manual = $this->postJson('/api/v1/accounts-payable/open-items', ['supplier_id' => $this->supplier->id, 'document_number' => 'MAN-1', 'document_date' => now()->toDateString(), 'due_date' => now()->addDays(20)->toDateString(), 'currency' => 'EUR', 'amount' => 80])->assertCreated();
    expect(payablesItem($this, 'MAN-1')['document_type'])->toBe('manual_bill')
        ->and($manual->json('data.id'))->toBe(payablesItem($this, 'MAN-1')['id']);
});

it('describes the Skonto opportunity of an item as of today', function (): void {
    $skonto = payablesItem($this, 'B-1')['skonto'];

    expect($skonto)->toBe(['percent' => '2.00', 'deadline' => now()->addDays(7)->toDateString(), 'days_remaining' => 7, 'savings' => '10.00', 'net_payable' => '490.00', 'terms' => '60 days, 2% within 10 days']);

    // Past the deadline the terms stay, with the days negative.
    $this->postJson('/api/v1/accounts-payable/supplier-terms', ['supplier_id' => $this->supplier->id, 'skonto_percentage' => 1.5, 'skonto_days' => 4])->assertCreated();
    expect(payablesItem($this, 'A-1')['skonto'])->toMatchArray(['percent' => '1.50', 'days_remaining' => -6, 'savings' => '15.00', 'net_payable' => '985.00', 'terms' => '1.5% within 4 days']);
});

it('filters the items by status, document type, Skonto and balance', function (): void {
    $numbers = fn (string $query): array => collect(payablesItems($this, $query)['data'])->pluck('document_number')->sort()->values()->all();

    expect($numbers('document_type=qr_bill'))->toBe(['G-QR'])
        ->and($numbers('document_type=credit_note'))->toBe(['A-NC'])
        ->and($numbers('document_type=xml_invoice'))->toBe(['A-1', 'A-2', 'A-3', 'B-1'])
        ->and($numbers('skonto_only=1'))->toBe(['B-1'])
        ->and($numbers('supplier_id='.$this->beta->id))->toBe(['B-1'])
        ->and($numbers('due_to='.now()->addDays(5)->toDateString().'&status=open'))->toBe(['A-1', 'A-2', 'A-NC'])
        ->and($numbers('open_only=1'))->toHaveCount(6)
        ->and($numbers('search=a-3'))->toBe(['A-3']);

    $this->getJson(PAYABLES.'/items?status=in_proposal')->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->getJson(PAYABLES.'/items?document_type=receipt')->assertUnprocessable()->assertJsonValidationErrors(['document_type']);

    // The Skonto filter pages the filtered list, not the whole one.
    $skonto = payablesItems($this, 'skonto_only=1&per_page=1');
    expect($skonto['meta']['total'])->toBe(1)->and($skonto['data'])->toHaveCount(1);
});

it('summarises the ledger', function (): void {
    $blocked = payablesItem($this, 'A-3')['id'];
    $this->postJson(PAYABLES."/items/{$blocked}/block", ['kind' => 'dispute', 'reason' => 'Wrong price'])->assertOk();

    // Invoices only: 1000 + 500 + 300 + 500 + 120.
    $summary = $this->getJson(PAYABLES.'/items/summary')->assertOk()->json('data');

    expect($summary)->toBe(['total_open' => '2420.00', 'total_overdue' => '0.00', 'skonto_savings' => '10.00', 'blocked_count' => 1, 'currency' => 'EUR']);
});

it('blocks, releases and keeps the history of an item', function (): void {
    $id = payablesItem($this, 'A-1')['id'];

    $this->postJson(PAYABLES."/items/{$id}/block", ['kind' => 'nonsense', 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['kind', 'reason']);
    $blocked = $this->postJson(PAYABLES."/items/{$id}/block", ['kind' => 'block', 'reason' => 'Supplier IBAN under verification'])->assertOk()->json('data');

    expect($blocked)->toMatchArray(['status' => 'blocked', 'block_reason' => 'Supplier IBAN under verification'])
        ->and($blocked['block_history'])->toHaveCount(1)
        ->and($blocked['block_history'][0])->toMatchArray(['kind' => 'block', 'reason' => 'Supplier IBAN under verification', 'user_name' => 'Accountant']);

    $this->postJson(PAYABLES."/items/{$id}/block", ['kind' => 'dispute', 'reason' => 'Again'])->assertStatus(409);
    $released = $this->postJson(PAYABLES."/items/{$id}/release", ['reason' => 'Verified'])->assertOk()->json('data');

    expect($released)->toMatchArray(['status' => 'open', 'block_reason' => null])
        ->and(array_column($released['block_history'], 'kind'))->toBe(['block', 'release'])
        ->and($this->getJson(PAYABLES."/items/{$id}")->assertOk()->json('data.block_history.1.reason'))->toBe('Verified');

    $this->postJson(PAYABLES."/items/{$id}/release", ['reason' => 'Again'])->assertStatus(409);
    $this->postJson(PAYABLES.'/items/'.Str::uuid().'/release', ['reason' => 'Unknown'])->assertNotFound();
});

it('previews the batch with the candidates, the credit notes, the exclusions and the transfers', function (): void {
    $disputed = payablesItem($this, 'A-3')['id'];
    $this->postJson(PAYABLES."/items/{$disputed}/block", ['kind' => 'dispute', 'reason' => 'Wrong price'])->assertOk();
    apInvoice($this, $this->supplier, 'A-4', '90.00', 1);
    apSync($this);
    $this->postJson(PAYABLES.'/items/'.payablesItem($this, 'A-4')['id'].'/block', ['kind' => 'block', 'reason' => 'Verification'])->assertOk();

    $preview = $this->postJson(PAYABLES.'/batches/preview', payablesBatchPayload())->assertOk()->json('data');
    $batch = $preview['batch'];

    expect($preview)->toMatchArray(['cutoff_date' => now()->addDays(10)->toDateString(), 'currency' => 'EUR'])
        ->and(collect($preview['candidates'])->pluck('document_number')->sort()->values()->all())->toBe(['A-1', 'A-2', 'B-1', 'G-QR'])
        ->and(collect($preview['credit_notes'])->pluck('document_number')->all())->toBe(['A-NC'])
        ->and(collect($preview['excluded'])->pluck('reason', 'item_id')->all())->toEqual([payablesItem($this, 'A-4')['id'] => 'blocked'])
        ->and($batch)->toMatchArray(['id' => 'preview', 'number' => null, 'status' => 'draft', 'currency' => 'EUR', 'prioritize_skonto' => true, 'approval' => null, 'executed_at' => null])
        ->and($batch['totals'])->toBe(['gross_amount' => '2120.00', 'set_off_amount' => '200.00', 'skonto_savings' => '10.00', 'net_amount' => '1910.00', 'transfers' => 3, 'lines' => 4]);

    $alfa = collect($batch['transfers'])->firstWhere('iban', 'IT60X0542811101000000123456');

    expect($alfa)->toMatchArray(['supplier_name' => 'Fornitore Alfa SRL', 'currency' => 'EUR', 'gross_amount' => '1500.00', 'set_off_amount' => '200.00', 'skonto_savings' => '0.00', 'net_amount' => '1300.00'])
        ->and(collect($alfa['lines'])->pluck('document_number')->sort()->values()->all())->toBe(['A-1', 'A-2'])
        ->and(collect($alfa['lines'])->firstWhere('document_number', 'A-2'))->toMatchArray(['open_amount' => '500.00', 'pay_amount' => '500.00', 'skonto_taken' => false, 'skonto_savings' => '0.00'])
        ->and(collect($batch['transfers'])->firstWhere('iban', 'IT84S0542811101000000654321')['lines'][0])->toMatchArray(['document_number' => 'B-1', 'open_amount' => '500.00', 'pay_amount' => '490.00', 'skonto_taken' => true, 'skonto_savings' => '10.00'])
        ->and($batch['set_offs'])->toBe([['credit_note_id' => payablesItem($this, 'A-NC')['id'], 'credit_note_number' => 'A-NC', 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Fornitore Alfa SRL', 'amount' => '200.00', 'applied_to_item_ids' => [payablesItem($this, 'A-2')['id']]]]);
});

it('narrows the preview to the items and credit notes ticked', function (): void {
    $a1 = payablesItem($this, 'A-1')['id'];
    $credit = payablesItem($this, 'A-NC')['id'];

    $only = $this->postJson(PAYABLES.'/batches/preview', payablesBatchPayload(['item_ids' => [$a1], 'set_off_ids' => []]))->assertOk()->json('data');

    // The candidates stay what the cutoff selects; the batch holds what was ticked, without netting.
    expect(collect($only['candidates'])->pluck('document_number')->sort()->values()->all())->toBe(['A-1', 'A-2', 'B-1', 'G-QR'])
        ->and($only['batch']['totals'])->toBe(['gross_amount' => '1000.00', 'set_off_amount' => '0.00', 'skonto_savings' => '0.00', 'net_amount' => '1000.00', 'transfers' => 1, 'lines' => 1])
        ->and($only['batch']['set_offs'])->toBe([]);

    $netted = $this->postJson(PAYABLES.'/batches/preview', payablesBatchPayload(['item_ids' => [$a1], 'set_off_ids' => [$credit]]))->assertOk()->json('data.batch');
    expect($netted['totals']['set_off_amount'])->toBe('200.00')->and($netted['totals']['net_amount'])->toBe('800.00');

    // An invoice that is not due can be ticked too, and a supplier filter and the currency apply.
    $later = $this->postJson(PAYABLES.'/batches/preview', payablesBatchPayload(['item_ids' => [payablesItem($this, 'A-3')['id']], 'supplier_id' => $this->supplier->id]))->assertOk()->json('data.batch.totals');
    expect($later['gross_amount'])->toBe('300.00');
    $this->postJson(PAYABLES.'/batches/preview', payablesBatchPayload(['currency' => 'CHF']))->assertOk()->assertJsonPath('data.batch.totals.lines', 0);
});

it('validates the criteria of a batch', function (): void {
    $this->postJson(PAYABLES.'/batches/preview', [])->assertUnprocessable()->assertJsonValidationErrors(['cutoff_date', 'prioritize_skonto']);
    $this->postJson(PAYABLES.'/batches', payablesBatchPayload())->assertUnprocessable()->assertJsonValidationErrors(['item_ids', 'set_off_ids']);
    $this->postJson(PAYABLES.'/batches', payablesBatchPayload(['item_ids' => [], 'set_off_ids' => []]))->assertUnprocessable()->assertJsonValidationErrors(['item_ids']);
    $this->postJson(PAYABLES.'/batches/preview', payablesBatchPayload(['supplier_id' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors(['supplier_id']);
});

it('takes a batch from draft to executed with the statuses and the approval of the client', function (): void {
    $itemIds = [payablesItem($this, 'A-1')['id'], payablesItem($this, 'A-2')['id'], payablesItem($this, 'B-1')['id']];
    $created = $this->postJson(PAYABLES.'/batches', payablesBatchPayload(['item_ids' => $itemIds, 'set_off_ids' => [payablesItem($this, 'A-NC')['id']]]))->assertCreated()->json('data');
    $base = PAYABLES."/batches/{$created['id']}";

    expect($created)->toMatchArray(['status' => 'draft', 'currency' => 'EUR', 'prioritize_skonto' => true, 'approval' => null, 'executed_at' => null])
        ->and($created['number'])->toStartWith('DP-')
        ->and($created['totals'])->toBe(['gross_amount' => '2000.00', 'set_off_amount' => '200.00', 'skonto_savings' => '10.00', 'net_amount' => '1790.00', 'transfers' => 2, 'lines' => 3])
        ->and(payablesItem($this, 'A-1')['status'])->toBe('in_batch')
        ->and(payablesItem($this, 'A-1')['batch_id'])->toBe($created['id'])
        ->and(payablesItem($this, 'A-3')['batch_id'])->toBeNull();

    $this->postJson("{$base}/approve")->assertForbidden();
    $this->postJson("{$base}/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval');
    $this->getJson(PAYABLES.'/batches?status=pending_approval')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(PAYABLES.'/batches?status=draft')->assertOk()->assertJsonCount(0, 'data');

    treasuryActAs($this, $this->admin);
    $approved = $this->postJson("{$base}/approve", ['reason' => 'Cash flow is fine'])->assertOk()->json('data');

    expect($approved['status'])->toBe('approved')
        ->and($approved['approval'])->toMatchArray(['user_name' => 'Admin', 'decision' => 'approved', 'reason' => null])
        ->and($approved['approval']['decided_at'])->not->toBeNull()
        ->and(payablesItem($this, 'A-1')['status'])->toBe('authorized');

    $executed = $this->postJson("{$base}/execute")->assertOk()->json('data');

    expect($executed['status'])->toBe('executed')
        ->and($executed['executed_at'])->not->toBeNull()
        ->and(payablesItem($this, 'A-1')['status'])->toBe('paid')
        ->and(payablesItem($this, 'B-1')['open_amount'])->toBe('0.00')
        ->and(payablesItem($this, 'A-3')['status'])->toBe('open')
        ->and($this->getJson("{$base}")->assertOk()->json('data.status'))->toBe('executed');
});

it('lets the CFO reject a batch with a reason and frees its items', function (): void {
    $created = $this->postJson(PAYABLES.'/batches', payablesBatchPayload(['item_ids' => [payablesItem($this, 'A-1')['id']], 'set_off_ids' => []]))->assertCreated()->json('data');
    $base = PAYABLES."/batches/{$created['id']}";
    $this->postJson("{$base}/submit")->assertOk();

    treasuryActAs($this, $this->admin);
    $this->postJson("{$base}/reject", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $rejected = $this->postJson("{$base}/reject", ['reason' => 'Not this month'])->assertOk()->json('data');

    expect($rejected['status'])->toBe('rejected')
        ->and($rejected['approval'])->toMatchArray(['user_name' => 'Admin', 'decision' => 'rejected', 'reason' => 'Not this month'])
        ->and(payablesItem($this, 'A-1')['status'])->toBe('open')
        ->and(payablesItem($this, 'A-1')['batch_id'])->toBeNull();
});

it('cancels a batch, refuses a held item and an unknown one, and reports the held items of a batch', function (): void {
    $a1 = payablesItem($this, 'A-1')['id'];
    $created = $this->postJson(PAYABLES.'/batches', payablesBatchPayload(['item_ids' => [$a1, payablesItem($this, 'A-2')['id']], 'set_off_ids' => []]))->assertCreated()->json('data');
    $base = PAYABLES."/batches/{$created['id']}";

    $this->postJson(PAYABLES."/items/{$a1}/block", ['kind' => 'dispute', 'reason' => 'Quantities'])->assertOk();
    $refused = $this->postJson("{$base}/submit")->assertStatus(409);

    expect(apErrorCode($refused))->toBe('run_contains_held_items');
    $this->postJson("{$base}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(payablesItem($this, 'A-2')['status'])->toBe('open')
        ->and(payablesItem($this, 'A-1')['status'])->toBe('disputed');
    $this->getJson(PAYABLES.'/batches/'.Str::uuid())->assertNotFound();

    // A held item cannot be put in a batch.
    $none = $this->postJson(PAYABLES.'/batches', payablesBatchPayload(['item_ids' => [$a1], 'set_off_ids' => []]))->assertUnprocessable();
    expect(apErrorCode($none))->toBe('no_items_match');
});

it('ages the payables by supplier in the shape of the client and exports the report', function (): void {
    apInvoice($this, $this->supplier, 'A-OLD', '50.00', -40);
    apInvoice($this, $this->beta, 'B-OLD', '70.00', -5);

    $aging = $this->getJson(PAYABLES.'/aging')->assertOk()->json('data');
    $alfa = collect($aging['suppliers'])->firstWhere('supplier_name', 'Fornitore Alfa SRL');

    expect($aging)->toMatchArray(['as_of' => now()->toDateString(), 'currency' => 'EUR', 'available_currencies' => ['EUR']])
        ->and(array_column($aging['buckets'], 'key'))->toBe(['current', '1_30', '31_60', '61_90', 'over_90'])
        ->and($aging['totals'])->toBe(['current' => '2420.00', '1_30' => '70.00', '31_60' => '50.00', '61_90' => '0.00', 'over_90' => '0.00'])
        ->and($aging['total_open'])->toBe('2540.00')
        ->and($aging['total_overdue'])->toBe('120.00')
        ->and($alfa)->toMatchArray(['total_open' => '1850.00', 'total_overdue' => '50.00', 'items' => 5])
        ->and($alfa['buckets'])->toBe(['current' => '1800.00', '1_30' => '0.00', '31_60' => '50.00', '61_90' => '0.00', 'over_90' => '0.00']);

    $xlsx = $this->get(PAYABLES.'/aging/export/xlsx?locale=de')->assertOk();
    $pdf = $this->get(PAYABLES.'/aging/export/pdf')->assertOk();

    expect($xlsx->headers->get('Content-Type'))->toContain('spreadsheetml.sheet')
        ->and(str_starts_with($xlsx->getContent(), 'PK'))->toBeTrue()
        ->and(str_starts_with($pdf->getContent(), '%PDF'))->toBeTrue();
    $this->get(PAYABLES.'/aging/export/csv')->assertNotFound();
});

it('keeps the access rules of the module on the client routes', function (): void {
    $id = payablesItem($this, 'A-1')['id'];

    treasuryActor($this, 'accountant_readonly');
    $this->getJson(PAYABLES.'/items')->assertOk();
    $this->getJson(PAYABLES.'/batches')->assertOk();
    $this->postJson(PAYABLES.'/batches', payablesBatchPayload(['item_ids' => [$id], 'set_off_ids' => []]))->assertForbidden();

    treasuryActor($this, 'member');
    $this->getJson(PAYABLES.'/items')->assertForbidden();
    $this->getJson(PAYABLES.'/aging')->assertForbidden();

    // The other company sees none of it.
    treasuryActor($this, 'accountant', $this->otherTenant);
    expect(payablesItems($this)['data'])->toBe([]);
    $this->getJson(PAYABLES."/items/{$id}")->assertNotFound();
});
