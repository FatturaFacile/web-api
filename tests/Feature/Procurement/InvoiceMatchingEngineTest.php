<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Enums\InvoiceMatchLineStatus;
use Modules\Procurement\Enums\InvoiceMatchStatus;
use Modules\Procurement\Enums\InvoiceMatchType;
use Modules\Procurement\Enums\MatchDiscrepancyReason;
use Modules\Procurement\Enums\MatchTrigger;
use Modules\Procurement\Services\InvoiceMatchingQueryService;
use Modules\Procurement\Services\InvoiceMatchResolutionService;
use Tests\Support\InvoiceMatchingFixtures as Fixtures;

require_once __DIR__.'/InvoiceMatchingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    matchingSetUp($this);
});

it('matches an invoice that agrees with the order and the goods received and releases it for payment', function (): void {
    $order = matchingReceivedOrder($this);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, $this->poLines);

    $run = matchingRun($this, $invoice, (string) $order->id);

    expect($run->status)->toBe(InvoiceMatchStatus::Matched)
        ->and($run->match_type)->toBe(InvoiceMatchType::ThreeWay)
        ->and($run->reference_source)->toBe('explicit')
        ->and($run->discrepancy_count)->toBe(0)
        ->and($run->variance_amount)->toBe('0.00')
        ->and($run->invoiced_total)->toBe('1100.00')
        ->and($run->gr_ir_amount)->toBe('1100.00')
        ->and($run->price_variance_amount)->toBe('0.00')
        ->and($run->lines)->toHaveCount(2)
        ->and($run->lines->every(fn ($line): bool => $line->status === InvoiceMatchLineStatus::Matched))->toBeTrue()
        ->and($invoice->fresh()->match_status)->toBe('matched')
        ->and($invoice->fresh()->isPaymentBlocked())->toBeFalse();
});

it('records the ordered, received and invoiced quantities and unit prices of every line', function (): void {
    $order = matchingReceivedOrder($this);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, [['Industrial Fan Unit', 10, '100.00'], ['Mounting Kit', 5, '20.00']]);

    $line = matchingRun($this, $invoice, (string) $order->id)->lines->first();

    expect($line->ordered_quantity)->toBe('10.0000')
        ->and($line->received_quantity)->toBe('10.0000')
        ->and($line->invoiced_quantity)->toBe('10.0000')
        ->and($line->ordered_unit_price)->toBe('100.000000')
        ->and($line->invoiced_unit_price)->toBe('100.000000')
        ->and($line->quantity_variance)->toBe('0.0000')
        ->and($line->price_variance)->toBe('0.000000')
        ->and($line->purchase_order_line_id)->not->toBeNull();
});

it('auto-approves a price drift within the percentage tolerance and keeps the invoice payable', function (): void {
    matchingTolerance($this, ['price_tolerance_percent' => 1]);
    $order = matchingReceivedOrder($this);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, [['Industrial Fan Unit', 10, '100.80'], ['Mounting Kit', 5, '20.00']]);

    $run = matchingRun($this, $invoice, (string) $order->id);
    $fan = $run->lines->firstWhere('line_number', 1);

    expect($run->status)->toBe(InvoiceMatchStatus::WithinTolerance)
        ->and($fan->status)->toBe(InvoiceMatchLineStatus::WithinTolerance)
        ->and($fan->amount_variance)->toBe('8.00')
        ->and($fan->allowed_amount_variance)->toBe('10.00')
        ->and($fan->price_variance_percent)->toBe('0.80')
        ->and($run->gr_ir_amount)->toBe('1100.00')
        ->and($run->price_variance_amount)->toBe('8.00')
        ->and($invoice->fresh()->isPaymentBlocked())->toBeFalse();
});

it('applies the absolute tolerance when it is larger than the percentage one', function (): void {
    matchingTolerance($this, ['price_tolerance_amount' => 5]);
    $order = matchingReceivedOrder($this);

    $within = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, [['Industrial Fan Unit', 10, '100.40'], ['Mounting Kit', 5, '20.00']]), (string) $order->id);
    expect($within->status)->toBe(InvoiceMatchStatus::WithinTolerance);

    $order2 = matchingReceivedOrder($this);
    $beyond = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, [['Industrial Fan Unit', 10, '100.60'], ['Mounting Kit', 5, '20.00']]), (string) $order2->id);
    expect($beyond->status)->toBe(InvoiceMatchStatus::BlockedDiscrepancy)
        ->and($beyond->lines->firstWhere('line_number', 1)->status)->toBe(InvoiceMatchLineStatus::PriceVariance);
});

it('blocks an invoice whose price drifts beyond the tolerance and records the exact variance', function (): void {
    $order = matchingReceivedOrder($this);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, [['Industrial Fan Unit', 10, '110.00'], ['Mounting Kit', 5, '20.00']]);

    $run = matchingRun($this, $invoice, (string) $order->id);
    $fan = $run->lines->firstWhere('line_number', 1);

    expect($run->status)->toBe(InvoiceMatchStatus::BlockedDiscrepancy)
        ->and($run->discrepancy_count)->toBe(1)
        ->and($run->reasons)->toContain(MatchDiscrepancyReason::PriceAboveTolerance->value)
        ->and($run->blocked_at)->not->toBeNull()
        ->and($fan->price_variance)->toBe('10.000000')
        ->and($fan->price_variance_percent)->toBe('10.00')
        ->and($fan->amount_variance)->toBe('100.00')
        ->and($fan->status->isBlocking())->toBeTrue()
        ->and($invoice->fresh()->match_status)->toBe('blocked_discrepancy')
        ->and($invoice->fresh()->isPaymentBlocked())->toBeTrue();
});

it('does not block an invoice that is cheaper than the order', function (): void {
    $order = matchingReceivedOrder($this);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, [['Industrial Fan Unit', 10, '95.00'], ['Mounting Kit', 5, '20.00']]);

    $run = matchingRun($this, $invoice, (string) $order->id);

    expect($run->status)->toBe(InvoiceMatchStatus::WithinTolerance)
        ->and($run->lines->firstWhere('line_number', 1)->amount_variance)->toBe('-50.00');
});

it('blocks billing beyond the quantity received', function (): void {
    $order = Fixtures::order($this->tenant, $this->supplier, $this->poLines);
    Fixtures::receive($this->tenant, $order, [1 => 5, 2 => 5], (string) $this->owner->id);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, $this->poLines);

    $run = matchingRun($this, $invoice, (string) $order->id);
    $fan = $run->lines->firstWhere('line_number', 1);

    expect($run->status)->toBe(InvoiceMatchStatus::BlockedDiscrepancy)
        ->and($fan->status)->toBe(InvoiceMatchLineStatus::QuantityVariance)
        ->and($fan->received_quantity)->toBe('5.0000')
        ->and($fan->quantity_variance)->toBe('5.0000')
        ->and($fan->reasons)->toContain(MatchDiscrepancyReason::QuantityAboveReceived->value);
});

it('counts the goods returned to the supplier as not received', function (): void {
    $order = Fixtures::order($this->tenant, $this->supplier, $this->poLines);
    Fixtures::receive($this->tenant, $order, [1 => 10, 2 => 5], (string) $this->owner->id, [1 => 4]);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, $this->poLines);

    $run = matchingRun($this, $invoice, (string) $order->id);

    expect($run->lines->firstWhere('line_number', 1)->received_quantity)->toBe('6.0000')
        ->and($run->status)->toBe(InvoiceMatchStatus::BlockedDiscrepancy);
});

it('allows a partial invoice for what was received so far', function (): void {
    $order = Fixtures::order($this->tenant, $this->supplier, $this->poLines);
    Fixtures::receive($this->tenant, $order, [1 => 4], (string) $this->owner->id);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, [['Industrial Fan Unit', 4, '100.00']]);

    $run = matchingRun($this, $invoice, (string) $order->id);

    expect($run->status)->toBe(InvoiceMatchStatus::Matched)
        ->and($run->gr_ir_amount)->toBe('400.00');
});

it('blocks a second invoice for goods that an earlier invoice already billed', function (): void {
    $order = matchingReceivedOrder($this);
    $first = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, $this->poLines), (string) $order->id);
    expect($first->status)->toBe(InvoiceMatchStatus::Matched);

    $second = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, $this->poLines), (string) $order->id);
    $fan = $second->lines->firstWhere('line_number', 1);

    expect($second->status)->toBe(InvoiceMatchStatus::BlockedDiscrepancy)
        ->and($fan->previously_invoiced_quantity)->toBe('10.0000')
        ->and($fan->reasons)->toContain(MatchDiscrepancyReason::DuplicateBilling->value);
});

it('blocks an invoice for goods that were never received', function (): void {
    $order = Fixtures::order($this->tenant, $this->supplier, $this->poLines);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, $this->poLines);

    $run = matchingRun($this, $invoice, (string) $order->id);

    expect($run->status)->toBe(InvoiceMatchStatus::BlockedDiscrepancy)
        ->and($run->lines->every(fn ($line): bool => $line->status === InvoiceMatchLineStatus::NotReceived))->toBeTrue()
        ->and($run->reasons)->toBe([MatchDiscrepancyReason::NotReceived->value]);
});

it('matches service orders in 2 ways, against the order only, and still catches price drift and duplicate billing', function (): void {
    $order = Fixtures::order($this->tenant, $this->supplier, [['description' => 'Consulting hours', 'quantity' => 40, 'price' => '150.00', 'receipt_required' => false]]);

    $matched = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, [['Consulting hours', 40, '150.00']]), (string) $order->id);
    expect($matched->status)->toBe(InvoiceMatchStatus::Matched)
        ->and($matched->match_type)->toBe(InvoiceMatchType::TwoWay)
        ->and($matched->gr_ir_amount)->toBe('0.00')
        ->and($matched->lines->first()->receipt_required)->toBeFalse();

    $duplicate = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, [['Consulting hours', 40, '150.00']]), (string) $order->id);
    expect($duplicate->status)->toBe(InvoiceMatchStatus::BlockedDiscrepancy)
        ->and($duplicate->lines->first()->reasons)->toContain(MatchDiscrepancyReason::DuplicateBilling->value);

    $other = Fixtures::order($this->tenant, $this->supplier, [['description' => 'Audit services', 'quantity' => 10, 'price' => '200.00', 'receipt_required' => false]]);
    $drift = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, [['Audit services', 10, '220.00']]), (string) $other->id);
    expect($drift->status)->toBe(InvoiceMatchStatus::BlockedDiscrepancy)
        ->and($drift->reasons)->toContain(MatchDiscrepancyReason::PriceAboveTolerance->value);
});

it('treats subcontracting orders as 2-way', function (): void {
    $order = Fixtures::order($this->tenant, $this->supplier, [['Machining service', 100, '12.00']], ['order_type' => 'subcontract']);
    $run = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, [['Machining service', 100, '12.00']]), (string) $order->id);

    expect($run->match_type)->toBe(InvoiceMatchType::TwoWay)->and($run->status)->toBe(InvoiceMatchStatus::Matched);
});

it('blocks an invoice line that matches no order line', function (): void {
    $order = matchingReceivedOrder($this);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, [['Industrial Fan Unit', 10, '100.00'], ['Expedited shipping surcharge', 1, '75.00']]);

    $run = matchingRun($this, $invoice, (string) $order->id);
    $surcharge = $run->lines->firstWhere('line_number', 2);

    expect($run->status)->toBe(InvoiceMatchStatus::BlockedDiscrepancy)
        ->and($surcharge->status)->toBe(InvoiceMatchLineStatus::Unmatched)
        ->and($surcharge->purchase_order_line_id)->toBeNull()
        ->and($surcharge->reasons)->toBe([MatchDiscrepancyReason::UnmatchedLine->value]);
});

it('blocks an invoice without any purchase order', function (): void {
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, [['Anything', 1, '10.00']]);

    $run = matchingRun($this, $invoice);

    expect($run->purchase_order_id)->toBeNull()
        ->and($run->status)->toBe(InvoiceMatchStatus::BlockedDiscrepancy)
        ->and($run->reasons)->toBe([MatchDiscrepancyReason::NoPurchaseOrder->value])
        ->and($run->reference_source)->toBe('none')
        ->and($invoice->fresh()->isPaymentBlocked())->toBeTrue();
});

it('finds the purchase order in the DatiOrdineAcquisto of a FatturaPA XML', function (): void {
    $order = matchingReceivedOrder($this);
    $xml = '<p:FatturaElettronica><FatturaElettronicaBody><DatiGenerali><DatiOrdineAcquisto><RiferimentoNumeroLinea>1</RiferimentoNumeroLinea><IdDocumento>'.$order->po_number.'</IdDocumento></DatiOrdineAcquisto></DatiGenerali></FatturaElettronicaBody></p:FatturaElettronica>';

    $run = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, $this->poLines, ['xml_payload' => $xml]));

    expect($run->purchase_order_id)->toBe((string) $order->id)
        ->and($run->reference_source)->toBe('xml')
        ->and($run->reference_value)->toBe($order->po_number)
        ->and($run->status)->toBe(InvoiceMatchStatus::Matched);
});

it('finds the purchase order in the order reference of a Swiss eBill', function (): void {
    $order = matchingReceivedOrder($this);
    $xml = '<Invoice><BuyerOrderNumber>'.$order->po_number.'</BuyerOrderNumber></Invoice>';

    $run = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, $this->poLines, ['xml_payload' => $xml]));

    expect($run->purchase_order_id)->toBe((string) $order->id)->and($run->reference_source)->toBe('ebill');
});

it('finds the order number in the text an OCR read from a QR-bill', function (): void {
    $order = matchingReceivedOrder($this);
    $text = 'Payment for order '.strtolower((string) $order->po_number).' thank you';

    $run = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, $this->poLines, ['qr_raw_payload' => $text]));

    expect($run->purchase_order_id)->toBe((string) $order->id)->and($run->reference_source)->toBe('ocr');
});

it('falls back to the supplier, description and unit price when the invoice carries no reference', function (): void {
    $order = matchingReceivedOrder($this);

    $run = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, $this->poLines));

    expect($run->purchase_order_id)->toBe((string) $order->id)
        ->and($run->reference_source)->toBe('fallback')
        ->and($run->status)->toBe(InvoiceMatchStatus::Matched);
});

it('does not guess when two orders fit the invoice equally well', function (): void {
    matchingReceivedOrder($this);
    matchingReceivedOrder($this);

    $run = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, $this->poLines));

    expect($run->purchase_order_id)->toBeNull()->and($run->reasons)->toBe([MatchDiscrepancyReason::NoPurchaseOrder->value]);
});

it('aligns an invoice line to the order line by the closest description', function (): void {
    $order = matchingReceivedOrder($this);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, [['Industrial fan unit 230V', 10, '100.00'], ['Mounting kit', 5, '20.00']]);

    $run = matchingRun($this, $invoice, (string) $order->id);

    expect($run->lines->every(fn ($line): bool => $line->purchase_order_line_id !== null))->toBeTrue()
        ->and($run->status)->toBe(InvoiceMatchStatus::Matched);
});

it('compares an invoice without lines as a whole against the value still billable', function (): void {
    $order = matchingReceivedOrder($this);

    $exact = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, [], ['total_net' => '1100.00', 'total_amount' => '1100.00']), (string) $order->id);
    expect($exact->status)->toBe(InvoiceMatchStatus::Matched)->and($exact->lines)->toHaveCount(1)->and($exact->gr_ir_amount)->toBe('1100.00');

    $other = matchingReceivedOrder($this);
    $over = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, [], ['total_net' => '1500.00', 'total_amount' => '1500.00']), (string) $other->id);
    expect($over->status)->toBe(InvoiceMatchStatus::BlockedDiscrepancy)
        ->and($over->reasons)->toContain(MatchDiscrepancyReason::HeaderAboveTolerance->value);
});

it('catches a second invoice without lines for a value that was already billed as a whole', function (): void {
    $order = matchingReceivedOrder($this);
    matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, [], ['total_net' => '1100.00', 'total_amount' => '1100.00']), (string) $order->id);

    $duplicate = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, [], ['total_net' => '1100.00', 'total_amount' => '1100.00']), (string) $order->id);

    expect($duplicate->status)->toBe(InvoiceMatchStatus::BlockedDiscrepancy)
        ->and($duplicate->reasons)->toBe([MatchDiscrepancyReason::DuplicateBilling->value]);
});

it('blocks an invoice in another currency when no exchange rate lets it be compared', function (): void {
    $order = matchingReceivedOrder($this);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, $this->poLines, ['currency' => 'CHF']);

    $run = matchingRun($this, $invoice, (string) $order->id);

    expect($run->status)->toBe(InvoiceMatchStatus::BlockedDiscrepancy)
        ->and($run->reasons)->toBe([MatchDiscrepancyReason::CurrencyMismatch->value]);
});

it('converts the order price with the exchange rates when the currencies differ', function (): void {
    $order = Fixtures::order($this->tenant, $this->supplier, [['Industrial Fan Unit', 10, '100.00']], ['currency' => 'EUR']);
    Fixtures::receive($this->tenant, $order, [1 => 10], (string) $this->owner->id);
    // One CHF is worth 1.25 EUR in the books of the company: 100 EUR per unit is 80 CHF.
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, [['Industrial Fan Unit', 10, '80.00']], ['currency' => 'CHF', 'exchange_rate' => '1.25']);

    $run = matchingRun($this, $invoice, (string) $order->id);

    expect($run->status)->toBe(InvoiceMatchStatus::Matched)
        ->and($run->lines->first()->ordered_unit_price)->toBe('80.000000');
});

it('keeps a human decision when an automatic or batch run evaluates the invoice again', function (): void {
    $order = matchingReceivedOrder($this);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, [['Industrial Fan Unit', 10, '110.00'], ['Mounting Kit', 5, '20.00']]);
    $run = matchingRun($this, $invoice, (string) $order->id);

    Fixtures::in($this->tenant, fn () => app(InvoiceMatchResolutionService::class)->override($run, (string) $this->owner->id, 'Agreed price increase'));

    $again = matchingRun($this, $invoice, null, MatchTrigger::Batch);

    expect($again->id)->toBe($run->id)->and($again->fresh()->status)->toBe(InvoiceMatchStatus::OverrideApproved);
});

it('keeps only the latest run of an invoice current and the earlier ones as history', function (): void {
    $order = Fixtures::order($this->tenant, $this->supplier, $this->poLines);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, $this->poLines);

    $first = matchingRun($this, $invoice, (string) $order->id);
    Fixtures::receive($this->tenant, $order, [1 => 10, 2 => 5], (string) $this->owner->id);
    $second = matchingRun($this, $invoice, (string) $order->id);

    $history = Fixtures::in($this->tenant, fn () => app(InvoiceMatchingQueryService::class)->historyOf((string) $invoice->id));

    expect($first->fresh()->is_current)->toBeFalse()
        ->and($second->is_current)->toBeTrue()
        ->and($second->status)->toBe(InvoiceMatchStatus::Matched)
        ->and($history)->toHaveCount(2)
        ->and($invoice->fresh()->isPaymentBlocked())->toBeFalse();
});

it('matches the not yet matched invoices in a batch and skips the ones with no purchase order unless asked', function (): void {
    $order = matchingReceivedOrder($this);
    $referenced = Fixtures::invoice($this->tenant, $this->supplier, $this->poLines, ['qr_raw_payload' => (string) $order->po_number]);
    $orphan = Fixtures::invoice($this->tenant, $this->supplier, [['Unrelated purchase', 1, '9.99']]);
    $creditNote = Fixtures::invoice($this->tenant, $this->supplier, $this->poLines, ['document_type' => 'TD04']);

    $summary = Fixtures::in($this->tenant, fn (): array => $this->matching->runBatch(null, 100));

    expect($summary['processed'])->toBe(1)
        ->and($summary['matched'])->toBe(1)
        ->and($summary['skipped'])->toBe(1)
        ->and($summary['blocked'])->toBe(0)
        ->and($referenced->fresh()->match_status)->toBe('matched')
        ->and($orphan->fresh()->match_status)->toBeNull()
        ->and($creditNote->fresh()->match_status)->toBeNull();

    $withUnreferenced = Fixtures::in($this->tenant, fn (): array => $this->matching->runBatch(null, 100, true));

    expect($withUnreferenced['blocked'])->toBe(1)->and($orphan->fresh()->isPaymentBlocked())->toBeTrue();
});

it('reaches a high automatic matching rate on structured invoices that quote a valid order', function (): void {
    matchingTolerance($this, ['price_tolerance_percent' => 1]);
    $lines = [['Industrial Fan Unit', 10, '100.00']];

    foreach (range(1, 20) as $index) {
        $order = Fixtures::order($this->tenant, $this->supplier, $lines);
        Fixtures::receive($this->tenant, $order, [1 => 10], (string) $this->owner->id);

        $price = match ($index) {
            19 => '100.50', // within the 1 % tolerance
            20 => '130.00', // a real discrepancy
            default => '100.00',
        };

        $xml = '<p:FatturaElettronica><DatiOrdineAcquisto><IdDocumento>'.$order->po_number.'</IdDocumento></DatiOrdineAcquisto></p:FatturaElettronica>';
        matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, [['Industrial Fan Unit', 10, $price]], ['xml_payload' => $xml]), null, MatchTrigger::Auto);
    }

    $summary = Fixtures::in($this->tenant, fn (): array => app(InvoiceMatchingQueryService::class)->summary());

    expect($summary['total'])->toBe(20)
        ->and($summary['auto_match_rate'])->toBeGreaterThanOrEqual(90.0)
        ->and($summary['blocked_count'])->toBe(1)
        ->and($summary['by_status']['within_tolerance'])->toBe(1);
});

it('notifies purchasing and accounts payable when an invoice is blocked for discrepancy', function (): void {
    $order = matchingReceivedOrder($this);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, [['Industrial Fan Unit', 10, '110.00'], ['Mounting Kit', 5, '20.00']]);

    $run = matchingRun($this, $invoice, (string) $order->id);

    $notification = DB::table('notifications')->where('notifiable_id', $this->owner->id)->first();
    $data = json_decode((string) $notification->data, true);

    expect($notification)->not->toBeNull()
        ->and($data['title_key'])->toBe('invoiceMatching.notification.blockedTitle')
        ->and($data['run_id'])->toBe((string) $run->id)
        ->and($data['reasons'])->toContain(MatchDiscrepancyReason::PriceAboveTolerance->value);
});

it('does not notify anybody for an invoice that matches', function (): void {
    $order = matchingReceivedOrder($this);
    matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, $this->poLines), (string) $order->id);

    expect(DB::table('notifications')->count())->toBe(0);
});
