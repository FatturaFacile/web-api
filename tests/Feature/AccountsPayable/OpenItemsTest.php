<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\AccountsPayable\Models\ApOpenItem;

require_once __DIR__.'/AccountsPayableSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    apSetUp($this);
});

it('lists the open items of the received invoices and credit notes with their source and creditor IBAN', function (): void {
    $xml = apInvoice($this, $this->supplier, 'A-XML', '1000.00');
    $qrPayload = "SPC\n0200\n1\nCH4431999123000889012\nS\nFornitore Alfa SRL";
    apInvoice($this, $this->supplier, 'A-QR', '200.00', 5, ['qr_raw_payload' => $qrPayload, 'currency' => 'CHF']);
    $credit = apCreditNote($this, $this->supplier, 'A-NC', '100.00');
    apInvoice($this, $this->supplier, 'A-RA', '1220.00', 5, ['withholding_tax_amount' => '200.00']);

    $items = collect(apSync($this)->json('data'))->keyBy('document_number');

    expect($items)->toHaveCount(4)
        ->and($items['A-XML'])->toMatchArray(['kind' => 'invoice', 'source' => 'electronic_xml', 'status' => 'open', 'original_amount' => '1000.00', 'open_amount' => '1000.00', 'creditor_iban' => 'IT60X0542811101000000123456', 'currency' => 'EUR', 'passive_invoice_id' => $xml->id, 'supplier_name' => 'Fornitore Alfa SRL', 'payment_block' => null])
        ->and($items['A-QR'])->toMatchArray(['source' => 'swiss_qr', 'creditor_iban' => 'CH4431999123000889012', 'currency' => 'CHF'])
        ->and($items['A-NC'])->toMatchArray(['kind' => 'credit_note', 'open_amount' => '100.00', 'passive_invoice_id' => $credit->id])
        ->and($items['A-RA']['original_amount'])->toBe('1020.00');
});

it('keeps the open item in line with the payments made on the invoice, partial and full', function (): void {
    $invoice = apInvoice($this, $this->supplier, 'P-1', '1000.00');
    apSync($this);

    $invoice->forceFill(['total_paid' => '400.00', 'payment_status' => 'partially_paid'])->save();
    $partial = collect(apSync($this)->json('data'))->firstWhere('document_number', 'P-1');

    expect($partial)->toMatchArray(['paid_amount' => '400.00', 'open_amount' => '600.00', 'status' => 'open']);

    $invoice->forceFill(['total_paid' => '1000.00', 'payment_status' => 'paid'])->save();
    $paid = $this->getJson('/api/v1/accounts-payable/open-items?status=paid')->assertOk()->json('data.0');

    expect($paid)->toMatchArray(['document_number' => 'P-1', 'open_amount' => '0.00', 'status' => 'paid', 'payment_block' => 'settled']);
});

it('filters the list by supplier, status, kind, due date and text', function (): void {
    $other = apSupplier($this->tenant, 'Fornitore Beta SPA', 'IT84S0542811101000000654321');
    apInvoice($this, $this->supplier, 'F-1', '100.00', 5);
    apInvoice($this, $this->supplier, 'F-2', '200.00', 40);
    apInvoice($this, $other, 'G-1', '300.00', 5);
    apCreditNote($this, $other, 'G-NC', '50.00', 5);

    $numbers = fn (string $query): array => collect($this->getJson("/api/v1/accounts-payable/open-items?{$query}")->assertOk()->json('data'))->pluck('document_number')->sort()->values()->all();

    expect($numbers('supplier_id='.$other->id))->toBe(['G-1', 'G-NC'])
        ->and($numbers('kind=credit_note'))->toBe(['G-NC'])
        ->and($numbers('due_to='.now()->addDays(10)->toDateString()))->toBe(['F-1', 'G-1', 'G-NC'])
        ->and($numbers('due_from='.now()->addDays(10)->toDateString()))->toBe(['F-2'])
        ->and($numbers('search=f-2'))->toBe(['F-2'])
        ->and($numbers('status=open&currency=EUR'))->toBe(['F-1', 'F-2', 'G-1', 'G-NC']);

    $this->getJson('/api/v1/accounts-payable/open-items?status=nonsense')->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $page = $this->getJson('/api/v1/accounts-payable/open-items?per_page=2&only_payable=1')->assertOk();
    expect($page->json('data'))->toHaveCount(2)->and($page->json('meta.total'))->toBe(4);
});

it('registers a manual open item that is not tied to an invoice', function (): void {
    $response = $this->postJson('/api/v1/accounts-payable/open-items', [
        'supplier_id' => $this->supplier->id, 'document_number' => 'MAN-1', 'document_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        'currency' => 'eur', 'amount' => 750.5, 'creditor_iban' => 'it60 x054 2811 1010 0000 0123 456', 'skonto_percentage' => 2, 'skonto_days' => 10,
    ])->assertCreated();

    expect($response->json('data'))->toMatchArray(['source' => 'manual', 'kind' => 'invoice', 'status' => 'open', 'currency' => 'EUR', 'original_amount' => '750.50', 'open_amount' => '750.50', 'creditor_iban' => 'IT60X0542811101000000123456', 'passive_invoice_id' => null, 'skonto_eligible' => true, 'skonto_percentage' => '2.00', 'skonto_savings_amount' => '15.01', 'net_payable_with_skonto' => '735.49']);

    // A manual item survives the sync of the received invoices.
    apSync($this);
    expect(ApOpenItem::withoutGlobalScopes()->where('document_number', 'MAN-1')->exists())->toBeTrue();

    $this->postJson('/api/v1/accounts-payable/open-items', ['supplier_id' => $this->supplier->id])->assertUnprocessable()->assertJsonValidationErrors(['document_number', 'document_date', 'due_date', 'currency', 'amount']);
    $this->postJson('/api/v1/accounts-payable/open-items', [
        'supplier_id' => $this->supplier->id, 'document_number' => 'MAN-2', 'document_date' => now()->toDateString(), 'due_date' => now()->subDay()->toDateString(), 'currency' => 'EUR', 'amount' => 10, 'skonto_percentage' => 2,
    ])->assertUnprocessable()->assertJsonValidationErrors(['due_date', 'skonto_days']);
});

it('puts an item in dispute, blocks it for the payment and keeps the audited history until it is released', function (): void {
    $invoice = apInvoice($this, $this->supplier, 'D-1', '500.00');
    apSync($this);
    $id = apItem($invoice)->id;

    $this->postJson("/api/v1/accounts-payable/open-items/{$id}/dispute", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);

    $disputed = $this->postJson("/api/v1/accounts-payable/open-items/{$id}/dispute", ['reason' => 'Goods damaged'])->assertOk();

    expect($disputed->json('data'))->toMatchArray(['status' => 'disputed', 'payment_block' => 'disputed'])
        ->and($disputed->json('data.hold'))->toMatchArray(['type' => 'dispute', 'reason' => 'Goods damaged', 'held_by' => $this->accountant->id])
        ->and($disputed->json('data.hold.held_at'))->not->toBeNull();

    // Already held: the second hold is refused, the code tells why.
    $second = $this->postJson("/api/v1/accounts-payable/open-items/{$id}/block", ['reason' => 'Missing documents'])->assertStatus(409);
    expect(apErrorCode($second))->toBe('open_item_already_held');

    // A held item is not payable and does not come back as "open" from the sync.
    apSync($this);
    $this->getJson('/api/v1/accounts-payable/open-items?only_payable=1')->assertOk()->assertJsonCount(0, 'data');

    $released = $this->postJson("/api/v1/accounts-payable/open-items/{$id}/release", ['reason' => 'Credit note received'])->assertOk();
    expect($released->json('data'))->toMatchArray(['status' => 'open', 'hold' => null, 'payment_block' => null]);

    $history = collect($this->getJson("/api/v1/accounts-payable/open-items/{$id}/history")->assertOk()->json('data'));

    expect($history->pluck('event')->all())->toBe(['disputed', 'released'])
        ->and($history[0])->toMatchArray(['from_status' => 'open', 'to_status' => 'disputed', 'reason' => 'Goods damaged', 'actor_id' => $this->accountant->id])
        ->and($history[1])->toMatchArray(['from_status' => 'disputed', 'to_status' => 'open', 'reason' => 'Credit note received']);

    $notHeld = $this->postJson("/api/v1/accounts-payable/open-items/{$id}/release", ['reason' => 'Again'])->assertStatus(409);
    expect(apErrorCode($notHeld))->toBe('open_item_not_held');
});

it('blocks an item for administrative reasons with the same effect as a dispute', function (): void {
    $invoice = apInvoice($this, $this->supplier, 'B-1', '500.00');
    apSync($this);
    $id = apItem($invoice)->id;

    $blocked = $this->postJson("/api/v1/accounts-payable/open-items/{$id}/block", ['reason' => 'Supplier IBAN under verification'])->assertOk();

    expect($blocked->json('data'))->toMatchArray(['status' => 'blocked', 'payment_block' => 'administrative_block'])
        ->and($blocked->json('data.hold.type'))->toBe('administrative_block');
});

it('refuses to hold an item that is already settled', function (): void {
    $invoice = apInvoice($this, $this->supplier, 'S-1', '500.00');
    apSync($this);
    $invoice->forceFill(['total_paid' => '500.00', 'payment_status' => 'paid'])->save();

    $response = $this->postJson('/api/v1/accounts-payable/open-items/'.apItem($invoice)->id.'/dispute', ['reason' => 'Too late'])->assertStatus(409);

    expect(apErrorCode($response))->toBe('open_item_settled');
});

it('does not pay an invoice that is not approved for payment', function (): void {
    $invoice = apInvoice($this, $this->supplier, 'N-1', '500.00');
    $invoice->forceFill(['approval_status' => 'pending'])->saveQuietly();

    $item = collect(apSync($this)->json('data'))->firstWhere('document_number', 'N-1');

    expect($item['payment_block'])->toBe('invoice_not_approved');
});

it('shows one item and answers 404 for an unknown one', function (): void {
    $invoice = apInvoice($this, $this->supplier, 'O-1', '500.00');
    apSync($this);

    $this->getJson('/api/v1/accounts-payable/open-items/'.apItem($invoice)->id)->assertOk()->assertJsonPath('data.document_number', 'O-1');
    $this->getJson('/api/v1/accounts-payable/open-items/'.Str::uuid())->assertNotFound();
    $this->getJson('/api/v1/accounts-payable/open-items/'.Str::uuid().'/history')->assertNotFound();
});
