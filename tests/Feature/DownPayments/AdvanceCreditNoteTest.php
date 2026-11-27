<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SDI\Enums\TipoDocumento;

require_once __DIR__.'/DownPaymentsSupport.php';

/*
 * The credit note (TD04) of an advance invoice, partial or total: the reversal posting on the customer advances, the per-VAT-rate
 * ledger, the reopening of the residual of the order and of the installment, and the reference to the advance in the XML.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    dpSetUp($this);
    $this->chain = dpOpenChain($this);
    $emitted = dpEmit($this, $this->chain['id'], dpInstallment($this->chain, 1)['id'])->assertOk()->json('data');
    $this->advanceId = $emitted['document_id'];
    $this->advanceLinkId = $emitted['link_id'];
    $this->url = "/api/v1/down-payment-chains/{$this->chain['id']}/links/{$this->advanceLinkId}/credit-note";
});

it('previews the credit note and the residual it reopens', function (): void {
    $preview = $this->postJson($this->url.'/preview', ['mode' => 'amount', 'amount' => '216.00'])->assertOk()->json('data');

    expect($preview['credited_gross'])->toBe('216.00')
        ->and($preview['credited_net'])->toBe('180.00')
        ->and($preview['credited_vat'])->toBe('36.00')
        ->and($preview['residual_before_gross'])->toBe('1008.00')
        ->and($preview['residual_after_gross'])->toBe('1224.00')
        ->and($preview['advance_residual_after_gross'])->toBe('216.00')
        ->and($preview['slices'])->toHaveCount(2);

    expect(dpChain($this, $this->chain['id'])['total_credited_advances_gross'])->toBe('0.00');
});

it('issues a partial credit note by amount and reopens the residual of the order', function (): void {
    $response = $this->postJson($this->url, ['mode' => 'amount', 'amount' => '216.00', 'reason' => 'Order reduced'])->assertCreated();
    $creditNote = dpInvoice($response->json('data.credit_note_id'));

    expect($creditNote->invoice_type)->toBe('credit_note')
        ->and(TipoDocumento::ofInvoice($creditNote))->toBe(TipoDocumento::Td04)
        ->and($creditNote->related_invoice_id)->toBe($this->advanceId)
        ->and((string) $creditNote->total_gross)->toBe('216.00');

    billingAssertBalanced($creditNote->id);
    // The reversal leaves the customer advances, not the sales returns
    expect(dpBalance($this->tenant, '2160'))->toBe('-180.00')
        ->and(dpBalance($this->tenant, '4090'))->toBe('0.00');

    $chain = dpChain($this, $this->chain['id']);
    $advance = collect($chain['links'])->firstWhere('link_type', 'advance_invoice_td02');
    $credit = collect($chain['links'])->firstWhere('link_type', 'credit_note_td04');

    expect($chain['total_credited_advances_gross'])->toBe('216.00')
        ->and($chain['residual_order_gross'])->toBe('1224.00')
        ->and($advance['status'])->toBe('partially_credited')
        ->and($advance['credited_gross_amount'])->toBe('216.00')
        ->and($credit['parent_link_id'])->toBe($advance['id'])
        ->and($credit['gross_amount'])->toBe('216.00')
        ->and(dpInstallment($chain, 1)['status'])->toBe('invoiced');

    $byRate = collect($advance['vat_lines'])->keyBy('vat_percentage');
    expect($byRate['22.000']['credited_net_amount'])->toBe('150.00')
        ->and($byRate['22.000']['residual_net_amount'])->toBe('150.00')
        ->and($byRate['10.000']['credited_net_amount'])->toBe('30.00');
});

it('references the advance in the electronic credit note', function (): void {
    $creditNoteId = $this->postJson($this->url, ['mode' => 'total'])->assertCreated()->json('data.credit_note_id');
    $xml = dpXml($creditNoteId);

    expect($xml)->toContain('<TipoDocumento>TD04</TipoDocumento>')
        ->and($xml)->toContain('<DatiFattureCollegate>')
        ->and($xml)->toContain('<IdDocumento>'.dpInvoice($this->advanceId)->invoice_number.'</IdDocumento>');
});

it('credits a whole advance, frees its installment and lets a replacement be issued', function (): void {
    $this->postJson($this->url, ['mode' => 'total'])->assertCreated();
    $chain = dpChain($this, $this->chain['id']);

    expect(collect($chain['links'])->firstWhere('link_type', 'advance_invoice_td02')['status'])->toBe('credited')
        ->and($chain['residual_order_gross'])->toBe('1440.00')
        ->and($chain['total_credited_advances_gross'])->toBe('432.00')
        ->and(dpInstallment($chain, 1)['status'])->toBe('planned')
        ->and(dpBalance($this->tenant, '2160'))->toBe('0.00');

    dpEmit($this, $this->chain['id'], dpInstallment($chain, 1)['id'])->assertOk();
    $this->postJson($this->url, ['mode' => 'total'])->assertUnprocessable()->assertJsonPath('code', 'advance_not_creditable');
});

it('credits chosen VAT lines only', function (): void {
    $this->postJson($this->url, ['mode' => 'lines', 'lines' => [['vat_rate' => '22', 'net' => '100.00']]])->assertCreated();
    $advance = collect(dpChain($this, $this->chain['id'])['links'])->firstWhere('link_type', 'advance_invoice_td02');
    $byRate = collect($advance['vat_lines'])->keyBy('vat_percentage');

    expect($advance['credited_gross_amount'])->toBe('122.00')
        ->and($byRate['22.000']['residual_net_amount'])->toBe('200.00')
        ->and($byRate['10.000']['credited_net_amount'])->toBe('0.00');
});

it('refuses a credit above what is left of the advance and malformed requests', function (): void {
    $this->postJson($this->url, ['mode' => 'lines', 'lines' => [['vat_rate' => '22', 'net' => '1000.00']]])->assertUnprocessable()->assertJsonPath('code', 'credit_exceeds_advance');
    $this->postJson($this->url, ['mode' => 'lines', 'lines' => [['vat_rate' => '4', 'net' => '10.00']]])->assertUnprocessable()->assertJsonPath('code', 'credit_line_invalid');
    $this->postJson($this->url, ['mode' => 'amount', 'amount' => '999.00'])->assertUnprocessable();
    $this->postJson($this->url, ['mode' => 'amount'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
    $this->postJson($this->url, ['mode' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors(['mode']);
    $this->postJson($this->url, [])->assertUnprocessable()->assertJsonValidationErrors(['mode']);
});

it('deducts only what is left of a credited advance in the final settlement', function (): void {
    $this->postJson($this->url, ['mode' => 'amount', 'amount' => '216.00'])->assertCreated();
    $preview = $this->postJson("/api/v1/down-payment-chains/{$this->chain['id']}/preview-final-settlement")->assertOk()->json('data');

    expect($preview['totals']['deducted_gross'])->toBe('216.00')
        ->and($preview['totals']['final_gross'])->toBe('1224.00');

    $invoice = dpInvoice($this->postJson("/api/v1/down-payment-chains/{$this->chain['id']}/generate-final-settlement")->assertCreated()->json('data.invoice_id'));
    billingAssertBalanced($invoice->id);
    expect(dpBalance($this->tenant, '2160'))->toBe('0.00');
});

it('lets only finance credit an advance and refuses a link that is not an advance', function (): void {
    $proforma = dpOpenChain($this, ['template' => null, 'installments' => [['trigger_type' => 'on_order_confirmation', 'calculation_mode' => 'percentage', 'percentage' => '10', 'instrument_type' => 'advance_proforma_request']]], (string) billingOrder($this->tenant, $this->customer)->id);
    $linkId = dpEmit($this, $proforma['id'], dpInstallment($proforma, 1)['id'])->assertOk()->json('data.link_id');
    $this->postJson("/api/v1/down-payment-chains/{$proforma['id']}/links/{$linkId}/credit-note", ['mode' => 'total'])->assertUnprocessable()->assertJsonPath('code', 'link_not_an_advance');

    billingActor($this, 'sales_manager');
    $this->postJson($this->url, ['mode' => 'total'])->assertForbidden();
});
