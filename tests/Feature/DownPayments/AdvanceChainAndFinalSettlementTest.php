<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\DownPayments\Models\DownPaymentLink;
use Modules\SDI\Enums\TipoDocumento;

require_once __DIR__.'/DownPaymentsSupport.php';

/*
 * The advance invoices (TD02) of a chain, their per-VAT-rate ledger, the posting to the customer advances and the final settlement
 * invoice that deducts them per rate and per advance, with the DatiFattureCollegate references and no suspense balance left.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    dpSetUp($this);
    $this->chain = dpOpenChain($this);
});

it('emits an advance invoice per VAT rate, typed TD02 and posted to the customer advances', function (): void {
    $emitted = dpEmit($this, $this->chain['id'], dpInstallment($this->chain, 1)['id'])->assertOk()->json('data');
    $invoice = dpInvoice($emitted['document_id']);

    expect($emitted['instrument'])->toBe('advance_invoice_immediate')
        ->and($invoice->is_deposit)->toBeTrue()
        ->and(TipoDocumento::ofInvoice($invoice))->toBe(TipoDocumento::Td02)
        ->and((string) $invoice->total_net)->toBe('360.00')
        ->and((string) $invoice->total_gross)->toBe('432.00')
        ->and($invoice->items)->toHaveCount(2)
        ->and($invoice->status)->toBe('posted');

    billingAssertBalanced($invoice->id);
    // The net of the advance is a liability towards the customer, never revenue; the VAT is due at once
    expect(dpBalance($this->tenant, '2160'))->toBe('-360.00')
        ->and(dpBalance($this->tenant, '4000'))->toBe('0.00')
        ->and(dpBalance($this->tenant, '2200'))->toBe('-72.00');

    $chain = dpChain($this, $this->chain['id']);
    expect($chain['total_advance_invoiced_net'])->toBe('360.00')
        ->and($chain['total_advance_invoiced_vat'])->toBe('72.00')
        ->and($chain['total_advance_invoiced_gross'])->toBe('432.00')
        ->and($chain['residual_order_gross'])->toBe('1008.00')
        ->and(dpInstallment($chain, 1)['status'])->toBe('invoiced')
        ->and(dpInstallment($chain, 1)['linked_invoice_id'])->toBe($invoice->id);

    $link = collect($chain['links'])->firstWhere('link_type', 'advance_invoice_td02');
    $byRate = collect($link['vat_lines'])->keyBy('vat_percentage');
    expect($link['status'])->toBe('issued')
        ->and($byRate['22.000']['net_amount'])->toBe('300.00')
        ->and($byRate['10.000']['net_amount'])->toBe('60.00')
        ->and($byRate['22.000']['residual_net_amount'])->toBe('300.00');
});

it('emits an installment only once and never more than the order holds', function (): void {
    $first = dpInstallment($this->chain, 1)['id'];
    dpEmit($this, $this->chain['id'], $first)->assertOk();
    dpEmit($this, $this->chain['id'], $first)->assertUnprocessable()->assertJsonPath('code', 'installment_not_planned');
});

it('shows the multi-VAT matrix with the residual at every rate', function (): void {
    dpEmit($this, $this->chain['id'], dpInstallment($this->chain, 1)['id'])->assertOk();
    $matrix = collect(dpChain($this, $this->chain['id'])['vat_matrix'])->keyBy('vat_rate');

    expect($matrix['22.00']['contract_net'])->toBe('1000.00')
        ->and($matrix['22.00']['invoiced_net'])->toBe('300.00')
        ->and($matrix['22.00']['residual_net'])->toBe('700.00')
        ->and($matrix['22.00']['residual_vat'])->toBe('154.00')
        ->and($matrix['10.00']['invoiced_net'])->toBe('60.00')
        ->and($matrix['10.00']['residual_net'])->toBe('140.00')
        ->and($matrix['10.00']['deductible_gross'])->toBe('66.00');
});

it('previews the final settlement with the exact deduction per VAT rate and advance', function (): void {
    foreach ([1, 2] as $sequence) {
        dpEmit($this, $this->chain['id'], dpInstallment($this->chain, $sequence)['id'])->assertOk();
    }

    $preview = $this->postJson("/api/v1/down-payment-chains/{$this->chain['id']}/preview-final-settlement")->assertOk()->json('data');

    expect($preview['deduction_lines'])->toHaveCount(4)
        ->and($preview['totals']['supply_net'])->toBe('1200.00')
        ->and($preview['totals']['supply_vat'])->toBe('240.00')
        ->and($preview['totals']['deducted_net'])->toBe('840.00')
        ->and($preview['totals']['deducted_vat'])->toBe('168.00')
        ->and($preview['totals']['final_net'])->toBe('360.00')
        ->and($preview['totals']['final_vat'])->toBe('72.00')
        ->and($preview['totals']['final_gross'])->toBe('432.00')
        ->and($preview['totals']['vat_rounding_difference'])->toBe('0.00')
        ->and($preview['linked_documents'])->toHaveCount(4)
        ->and($preview['linked_documents'][0]['riferimento_numero_linea'])->toBe(3)
        ->and($preview['blockers'])->toBe([])
        ->and(collect($preview['journal_preview'])->firstWhere('account', 'customer_advances')['debit'])->toBe('840.00');

    // The final invoice taxable is the supply taxable less the advances taxable, rate by rate
    $perRate = collect($preview['deduction_lines'])->groupBy('vat_rate')->map(fn ($rows) => $rows->sum(fn (array $row): float => (float) $row['net']));
    expect($perRate['22.00'])->toBe(700.0)->and($perRate['10.00'])->toBe(140.0);
});

it('generates the final settlement invoice with the negative deduction lines and closes the advances', function (): void {
    $advances = [];

    foreach ([1, 2] as $sequence) {
        $advances[] = dpEmit($this, $this->chain['id'], dpInstallment($this->chain, $sequence)['id'])->assertOk()->json('data.document_id');
    }

    $response = $this->postJson("/api/v1/down-payment-chains/{$this->chain['id']}/generate-final-settlement")->assertCreated();
    $invoice = dpInvoice($response->json('data.invoice_id'));

    expect($invoice->is_deposit)->toBeFalse()
        ->and(TipoDocumento::ofInvoice($invoice))->toBe(TipoDocumento::Td01)
        ->and($invoice->items)->toHaveCount(6)
        ->and((string) $invoice->total_net)->toBe('360.00')
        ->and((string) $invoice->total_tax)->toBe('72.00')
        ->and((string) $invoice->total_gross)->toBe('432.00')
        ->and($invoice->items->filter(fn ($item) => $item->deducted_invoice_item_id !== null))->toHaveCount(4)
        ->and($invoice->items->filter(fn ($item) => (float) $item->line_total < 0))->toHaveCount(4);

    billingAssertBalanced($invoice->id);
    // The customer advances close with no suspense balance, the revenue is the whole supply
    expect(dpBalance($this->tenant, '2160'))->toBe('0.00')
        ->and(dpBalance($this->tenant, '4000'))->toBe('-1200.00')
        ->and(dpBalance($this->tenant, '2200'))->toBe('-240.00');

    $chain = dpChain($this, $this->chain['id']);
    expect($chain['status'])->toBe('settled')
        ->and($chain['final_invoice_id'])->toBe($invoice->id)
        ->and($chain['deduction_lines'])->toHaveCount(4)
        ->and(collect($chain['deduction_lines'])->sum(fn (array $line): float => (float) $line['deducted_net']))->toBe(840.0)
        ->and(collect($chain['links'])->where('link_type', 'advance_invoice_td02')->every(fn (array $link): bool => $link['status'] === 'settled'))->toBeTrue()
        ->and(collect($chain['links'])->firstWhere('link_type', 'final_settlement_td01')['document_id'])->toBe($invoice->id);

    $residual = collect($chain['links'])->where('link_type', 'advance_invoice_td02')->flatMap(fn (array $link) => $link['vat_lines'])->sum(fn (array $line): float => (float) $line['residual_gross_amount']);
    expect($residual)->toBe(0.0);

    $this->postJson("/api/v1/down-payment-chains/{$this->chain['id']}/generate-final-settlement")->assertUnprocessable()->assertJsonPath('code', 'chain_already_settled');
    expect(DownPaymentLink::withoutGlobalScopes()->where('document_id', $advances[0])->first()->status)->toBe('settled');
});

it('references every deducted advance in the electronic invoice', function (): void {
    $numbers = [];

    foreach ([1, 2] as $sequence) {
        $id = dpEmit($this, $this->chain['id'], dpInstallment($this->chain, $sequence)['id'])->assertOk()->json('data.document_id');
        $numbers[] = dpInvoice($id)->invoice_number;
        expect(dpXml($id))->toContain('<TipoDocumento>TD02</TipoDocumento>');
    }

    $invoiceId = $this->postJson("/api/v1/down-payment-chains/{$this->chain['id']}/generate-final-settlement")->assertCreated()->json('data.invoice_id');
    $xml = dpXml($invoiceId);

    expect($xml)->toContain('<TipoDocumento>TD01</TipoDocumento>')
        ->and(substr_count($xml, '<DatiFattureCollegate>'))->toBe(4)
        ->and(substr_count($xml, '<NumItem>'))->toBe(4)
        ->and($xml)->toContain('<IdDocumento>'.$numbers[0].'</IdDocumento>')
        ->and($xml)->toContain('<IdDocumento>'.$numbers[1].'</IdDocumento>')
        ->and($xml)->toContain('<RiferimentoNumeroLinea>3</RiferimentoNumeroLinea>')
        ->and($xml)->toContain('<RiferimentoNumeroLinea>6</RiferimentoNumeroLinea>');
});

it('settles a chain with no advance at all as a plain invoice of the whole supply', function (): void {
    $invoice = dpInvoice($this->postJson("/api/v1/down-payment-chains/{$this->chain['id']}/generate-final-settlement")->assertCreated()->json('data.invoice_id'));

    expect($invoice->items)->toHaveCount(2)->and((string) $invoice->total_gross)->toBe('1440.00');
    expect(dpXml((string) $invoice->id))->not->toContain('DatiFattureCollegate');
});

it('settles a chain built from a contract with the deduction of its advances', function (): void {
    $chain = $this->postJson('/api/v1/down-payment-chains/from-source', [
        'source_type' => 'contract', 'source_id' => (string) Str::uuid(), 'template' => '50_50',
        'contract' => ['customer_id' => (string) $this->customer->id, 'number' => 'CT-9', 'lines' => [
            ['description' => 'Hardware', 'vat_rate' => '22', 'net' => '8000.00'],
            ['description' => 'Installation', 'vat_rate' => '10', 'net' => '2000.00'],
        ]],
    ])->assertCreated()->json('data');

    dpEmit($this, $chain['id'], dpInstallment($chain, 1)['id'])->assertOk();
    $invoice = dpInvoice($this->postJson("/api/v1/down-payment-chains/{$chain['id']}/generate-final-settlement")->assertCreated()->json('data.invoice_id'));

    expect((string) $invoice->total_net)->toBe('5000.00')->and((string) $invoice->total_tax)->toBe('980.00');
    billingAssertBalanced($invoice->id);
});

it('never lets advances take more than the order holds', function (): void {
    $chain = dpOpenChain($this, ['template' => null, 'installments' => [
        ['trigger_type' => 'on_order_confirmation', 'calculation_mode' => 'percentage', 'percentage' => '60', 'instrument_type' => 'advance_invoice_immediate'],
        ['trigger_type' => 'on_material_delivery', 'calculation_mode' => 'percentage', 'percentage' => '40', 'instrument_type' => 'advance_invoice_immediate'],
    ]], (string) billingOrder($this->tenant, $this->customer)->id);

    foreach ([1, 2] as $sequence) {
        dpEmit($this, $chain['id'], dpInstallment($chain, $sequence)['id'])->assertOk();
    }

    $chain = dpChain($this, $chain['id']);
    expect($chain['total_advance_invoiced_gross'])->toBe('1440.00')->and($chain['residual_order_gross'])->toBe('0.00');
});
