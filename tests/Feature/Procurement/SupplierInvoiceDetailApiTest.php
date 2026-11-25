<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\InvoiceMatchingFixtures as Fixtures;

require_once __DIR__.'/InvoiceMatchingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    matchingSetUp($this);
});

it('returns the supplier invoice with the name of its supplier and its fiscal amounts', function (): void {
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, $this->poLines);

    $response = $this->getJson("/api/v1/purchases/supplier-invoices/{$invoice->id}")->assertOk();

    $response->assertJsonPath('data.id', (string) $invoice->id)
        ->assertJsonPath('data.invoice_number', $invoice->invoice_number)
        ->assertJsonPath('data.supplier_id', (string) $this->supplier->id);
    expect($response->json('data'))->toHaveKeys(['supplier_name', 'total_amount', 'total_net', 'total_tax', 'payment_status', 'currency']);
});

it('lists the lines of the invoice and the history of its 3-way matches', function (): void {
    $order = matchingReceivedOrder($this);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, $this->poLines);
    $run = matchingRun($this, $invoice, (string) $order->id);

    $this->getJson("/api/v1/purchases/supplier-invoices/{$invoice->id}/lines")->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/procurement/invoice-matching/invoices/{$invoice->id}/history")->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $run->id);
});

it('answers 404 for unknown invoices and keeps the invoices of a company away from the others', function (): void {
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, $this->poLines);

    $this->getJson('/api/v1/purchases/supplier-invoices/'.Str::uuid7())->assertNotFound();

    treasuryActor($this, 'owner', $this->otherTenant);

    $this->getJson("/api/v1/purchases/supplier-invoices/{$invoice->id}")->assertNotFound();
});
