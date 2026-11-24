<?php

declare(strict_types=1);

use App\Models\PassiveInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GeneralLedger\Actions\PostPassiveInvoiceAction;
use Modules\GeneralLedger\Listeners\ProcurementAccountingListener;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Procurement\Enums\InvoiceMatchStatus;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Services\GrIrAccountingService;
use Modules\Procurement\Services\InvoiceMatchResolutionService;
use Tests\Support\InvoiceMatchingFixtures as Fixtures;

require_once __DIR__.'/InvoiceMatchingSupport.php';

uses(RefreshDatabase::class);

const GR_IR_API = '/api/v1/procurement/invoice-matching';

beforeEach(function (): void {
    matchingSetUp($this);
});

/**
 * An order with everything received, the goods receipt booked in the ledger (inventory debit, GR/IR credit).
 *
 * @return array{0: PurchaseOrder, 1: PurchaseReceipt}
 */
function grIrReceivedOrder(object $test): array
{
    $order = Fixtures::order($test->tenant, $test->supplier, $test->poLines);
    $receipt = Fixtures::receive($test->tenant, $order, [1 => 10, 2 => 5], (string) $test->owner->id);
    $lineIds = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->orderBy('line_number')->pluck('id')->all();

    Fixtures::in($test->tenant, fn () => app(ProcurementAccountingListener::class)->handleGoodsReceived(new GoodsReceivedEvent(
        receiptId: (string) $receipt->id,
        receiptNumber: (string) $receipt->receipt_number,
        purchaseOrderId: (string) $order->id,
        supplierDeliveryNoteRef: (string) $receipt->supplier_delivery_note_ref,
        receivedLines: [
            ['purchase_order_line_id' => (string) $lineIds[0], 'quantity_received' => 10.0, 'quantity_accepted' => 10.0, 'unit_cost' => 100.0],
            ['purchase_order_line_id' => (string) $lineIds[1], 'quantity_received' => 5.0, 'quantity_accepted' => 5.0, 'unit_cost' => 20.0],
        ],
        tenantId: (string) $test->tenant->id,
        companyId: (string) $test->tenant->id,
    )));

    return [$order, $receipt];
}

/**
 * The supplier invoice of the default lines (net 1,100 or the given fan price), with 22 % VAT on top, dated today.
 */
function grIrInvoice(object $test, string $fanPrice = '100.00'): PassiveInvoice
{
    $net = bcadd(bcmul('10', $fanPrice, 2), '100.00', 2);
    $tax = bcmul($net, '0.22', 2);

    return Fixtures::invoice($test->tenant, $test->supplier, [['Industrial Fan Unit', 10, $fanPrice], ['Mounting Kit', 5, '20.00']], [
        'date' => now()->toDateString(),
        'total_tax' => $tax,
        'total_amount' => bcadd($net, $tax, 2),
    ]);
}

it('accrues the goods received against GR/IR when the receipt is booked', function (): void {
    [, $receipt] = grIrReceivedOrder($this);

    $entry = JournalEntry::withoutGlobalScopes()->where('source_document_type', 'goods_receipt')->where('source_document_id', $receipt->id)->firstOrFail();

    expect(treasuryPostings('goods_receipt', (string) $receipt->id))->toBe([['1200', '1100.00', '0.00'], ['2010', '0.00', '1100.00']])
        ->and($entry->status)->toBe('posted');
});

it('reverses the accrual, books the input VAT and the payable when a matched invoice is posted', function (): void {
    [$order] = grIrReceivedOrder($this);
    $invoice = grIrInvoice($this);
    $run = matchingRun($this, $invoice, (string) $order->id);
    expect($run->status)->toBe(InvoiceMatchStatus::Matched);

    Fixtures::in($this->tenant, fn () => app(PostPassiveInvoiceAction::class)->execute($invoice->fresh()));

    // GR/IR debit 1,100 (accrual reversed), input VAT debit 242, accounts payable credit 1,342.
    expect(treasuryPostings('vendor_invoice', (string) $invoice->id))->toBe([
        ['2010', '1100.00', '0.00'],
        ['1400', '242.00', '0.00'],
        ['2000', '0.00', '1342.00'],
    ]);
});

it('shows the accrual, what the invoice cleared and the open GR/IR balance of a run', function (): void {
    [$order, $receipt] = grIrReceivedOrder($this);
    $invoice = grIrInvoice($this);
    $run = matchingRun($this, $invoice, (string) $order->id);

    $before = $this->getJson(GR_IR_API."/runs/{$run->id}/gr-ir")->assertOk()
        ->assertJsonPath('data.accrued_amount', '1100.00')
        ->assertJsonPath('data.cleared_amount', '0.00')
        ->assertJsonPath('data.open_balance', '1100.00')
        ->assertJsonPath('data.expected_clearing_amount', '1100.00')
        ->assertJsonCount(1, 'data.receipts')
        ->assertJsonPath('data.receipts.0.receipt_number', $receipt->receipt_number)
        ->assertJsonPath('data.receipts.0.clearing_amount', '1100.00')
        ->assertJsonPath('data.receipts.0.lines.0.account_code', '1200')
        ->assertJsonPath('data.invoice', null);
    expect($before->json('data.receipts.0.lines.1.credit_amount'))->toBe('1100.00');

    Fixtures::in($this->tenant, fn () => app(PostPassiveInvoiceAction::class)->execute($invoice->fresh()));

    $after = $this->getJson(GR_IR_API."/runs/{$run->id}/gr-ir")->assertOk()
        ->assertJsonPath('data.cleared_amount', '1100.00')
        ->assertJsonPath('data.open_balance', '0.00')
        ->assertJsonPath('data.invoice.clearing_amount', '1100.00');

    expect($after->json('data.invoice.lines'))->toHaveCount(3)
        ->and($after->json('data.invoice.lines.0.account_code'))->toBe('2010')
        ->and($after->json('data.invoice.lines.0.debit_amount'))->toBe('1100.00');
});

it('clears only the accrual of the goods received when a supervisor overrides a price variance, the rest is a cost', function (): void {
    [$order] = grIrReceivedOrder($this);
    $invoice = grIrInvoice($this, '110.00');
    $run = matchingRun($this, $invoice, (string) $order->id);
    expect($run->status)->toBe(InvoiceMatchStatus::BlockedDiscrepancy);

    Fixtures::in($this->tenant, fn () => app(InvoiceMatchResolutionService::class)->override($run, (string) $this->owner->id, 'Price increase agreed'));
    Fixtures::in($this->tenant, fn () => app(PostPassiveInvoiceAction::class)->execute($invoice->fresh()));

    // Net 1,200: 1,100 reverses the accrual, 100 is price variance on the purchase expense account.
    expect(treasuryPostings('vendor_invoice', (string) $invoice->id))->toBe([
        ['2010', '1100.00', '0.00'],
        ['6000', '100.00', '0.00'],
        ['1400', '264.00', '0.00'],
        ['2000', '0.00', '1464.00'],
    ]);
});

it('posts an invoice that was never matched, or is still blocked, as a plain cost without touching GR/IR', function (): void {
    [$order] = grIrReceivedOrder($this);
    $unmatched = grIrInvoice($this);
    Fixtures::in($this->tenant, fn () => app(PostPassiveInvoiceAction::class)->execute($unmatched));

    expect(treasuryPostings('vendor_invoice', (string) $unmatched->id)[0])->toBe(['6000', '1100.00', '0.00']);

    $blocked = grIrInvoice($this, '110.00');
    matchingRun($this, $blocked, (string) $order->id);
    Fixtures::in($this->tenant, fn () => app(PostPassiveInvoiceAction::class)->execute($blocked->fresh()));

    expect(treasuryPostings('vendor_invoice', (string) $blocked->id)[0])->toBe(['6000', '1200.00', '0.00']);
});

it('clears nothing for a service invoice matched in 2 ways: there is no goods receipt to reverse', function (): void {
    $order = Fixtures::order($this->tenant, $this->supplier, [['description' => 'Consulting hours', 'quantity' => 40, 'price' => '150.00', 'receipt_required' => false]]);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, [['Consulting hours', 40, '150.00']], ['date' => now()->toDateString()]);
    $run = matchingRun($this, $invoice, (string) $order->id);

    Fixtures::in($this->tenant, fn () => app(PostPassiveInvoiceAction::class)->execute($invoice->fresh()));

    expect($run->status)->toBe(InvoiceMatchStatus::Matched)
        ->and(app(GrIrAccountingService::class)->clearingFor($invoice->fresh()))->toBeNull()
        ->and(treasuryPostings('vendor_invoice', (string) $invoice->id)[0])->toBe(['6000', '6000.00', '0.00']);
});

it('never reverses the accrual for a supplier credit note', function (): void {
    [$order] = grIrReceivedOrder($this);
    $invoice = grIrInvoice($this);
    matchingRun($this, $invoice, (string) $order->id);
    $invoice->forceFill(['document_type' => 'TD04'])->save();

    expect(app(GrIrAccountingService::class)->clearingFor($invoice->fresh()))->toBeNull();
});
