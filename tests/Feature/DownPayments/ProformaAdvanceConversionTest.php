<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Treasury\Events\CollectionReceivedEvent;

require_once __DIR__.'/DownPaymentsSupport.php';

/*
 * The pro-forma advance request posts nothing until the customer pays; the payment (manual, or reconciled by Treasury) converts it
 * into the official advance invoice dated on the payment day and settles it.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    dpSetUp($this);
    $this->chain = dpOpenChain($this, ['template' => null, 'installments' => [
        ['trigger_type' => 'on_order_confirmation', 'calculation_mode' => 'percentage', 'percentage' => '50', 'instrument_type' => 'advance_proforma_request'],
    ]]);
    $this->emitted = dpEmit($this, $this->chain['id'], dpInstallment($this->chain, 1)['id'])->assertOk()->json('data');
});

it('issues a pro-forma that posts no VAT and no revenue and only reserves what it asks for', function (): void {
    $proforma = dpInvoice($this->emitted['document_id']);
    $chain = dpChain($this, $this->chain['id']);
    $link = collect($chain['links'])->firstWhere('link_type', 'proforma_advance_request');

    expect($proforma->invoice_type)->toBe('proforma')
        ->and($proforma->gl_journal_entry_id)->toBeNull()
        ->and($link['status'])->toBe('open')
        ->and($link['gross_amount'])->toBe('720.00')
        ->and($chain['total_advance_invoiced_gross'])->toBe('0.00')
        ->and($chain['residual_order_gross'])->toBe('720.00')
        ->and(dpInstallment($chain, 1)['status'])->toBe('requested')
        ->and(dpBalance($this->tenant, '2200'))->toBe('0.00')
        ->and(dpBalance($this->tenant, '2160'))->toBe('0.00');
});

it('converts a paid pro-forma into the TD02 dated on the payment day', function (): void {
    $paidAt = now()->subDay()->toDateString();
    $this->postJson("/api/v1/down-payment-chains/{$this->chain['id']}/proforma/{$this->emitted['link_id']}/confirm-payment", ['paid_at' => $paidAt, 'reference' => 'CRO-77'])->assertOk();

    $chain = dpChain($this, $this->chain['id']);
    $proformaLink = collect($chain['links'])->firstWhere('link_type', 'proforma_advance_request');
    $advanceLink = collect($chain['links'])->firstWhere('link_type', 'advance_invoice_td02');
    $advance = dpInvoice($advanceLink['document_id']);

    expect($proformaLink['status'])->toBe('converted')
        ->and($advanceLink['parent_link_id'])->toBe($proformaLink['id'])
        ->and($advanceLink['status'])->toBe('issued')
        ->and($advance->date->toDateString())->toBe($paidAt)
        ->and($advance->is_deposit)->toBeTrue()
        ->and($advance->proforma_id)->toBe($this->emitted['document_id'])
        ->and($advance->payment_status)->toBe('paid')
        ->and((string) $advance->total_gross)->toBe('720.00')
        ->and($chain['total_advance_invoiced_gross'])->toBe('720.00')
        ->and($chain['residual_order_gross'])->toBe('720.00')
        ->and(dpInstallment($chain, 1)['status'])->toBe('converted')
        ->and(dpBalance($this->tenant, '2160'))->toBe('-600.00')
        ->and(dpBalance($this->tenant, '2200'))->toBe('-120.00');

    billingAssertBalanced($advance->id);
});

it('converts the pro-forma only once', function (): void {
    $url = "/api/v1/down-payment-chains/{$this->chain['id']}/proforma/{$this->emitted['link_id']}/confirm-payment";
    $this->postJson($url, ['paid_at' => now()->toDateString()])->assertOk();
    $this->postJson($url, ['paid_at' => now()->toDateString()])->assertOk();

    expect(SalesInvoice::withoutGlobalScopes()->where('is_deposit', true)->where('invoice_type', '!=', 'proforma')->count())->toBe(1);
});

it('converts the pro-forma when Treasury reconciles the collection against it', function (): void {
    event(new CollectionReceivedEvent(
        collectionId: 'COL-1', customerId: (string) $this->customer->id, invoiceId: $this->emitted['document_id'], collectedAmount: 720.0,
        collectionDate: now()->toDateString(), tenantId: (string) $this->tenant->id, companyId: (string) $this->tenant->id, eventId: (string) Str::uuid(),
    ));

    $chain = dpChain($this, $this->chain['id']);
    $advance = collect($chain['links'])->firstWhere('link_type', 'advance_invoice_td02');

    expect($advance)->not->toBeNull()
        ->and(dpInvoice($advance['document_id'])->payment_status)->toBe('paid')
        ->and(collect($chain['links'])->firstWhere('link_type', 'proforma_advance_request')['status'])->toBe('converted');
});

it('leaves the pro-forma open when the collection does not cover it', function (): void {
    event(new CollectionReceivedEvent(
        collectionId: 'COL-2', customerId: (string) $this->customer->id, invoiceId: $this->emitted['document_id'], collectedAmount: 100.0,
        collectionDate: now()->toDateString(), tenantId: (string) $this->tenant->id, companyId: (string) $this->tenant->id, eventId: (string) Str::uuid(),
    ));

    expect(collect(dpChain($this, $this->chain['id'])['links'])->firstWhere('link_type', 'proforma_advance_request')['status'])->toBe('open');
});

it('blocks the final settlement while a pro-forma request is open', function (): void {
    $preview = $this->postJson("/api/v1/down-payment-chains/{$this->chain['id']}/preview-final-settlement")->assertOk()->json('data');

    expect($preview['blockers'])->toBe([['code' => 'open_proforma', 'id' => $this->emitted['link_id']]]);
    $this->postJson("/api/v1/down-payment-chains/{$this->chain['id']}/generate-final-settlement")->assertUnprocessable()->assertJsonPath('code', 'open_proformas');
});

it('validates the payment date of the confirmation', function (): void {
    $url = "/api/v1/down-payment-chains/{$this->chain['id']}/proforma/{$this->emitted['link_id']}/confirm-payment";
    $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['paid_at']);
    $this->postJson($url, ['paid_at' => now()->addDays(3)->toDateString()])->assertUnprocessable()->assertJsonValidationErrors(['paid_at']);
});
