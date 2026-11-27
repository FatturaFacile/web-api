<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\DownPayments\Models\DownPaymentChain;

require_once __DIR__.'/DownPaymentsSupport.php';

/*
 * The installment plan wizard and the opening of a chain: presets, the cent-exact preview over the VAT rates, creation from a sales
 * order and a contract, one chain per source, validation, tenant isolation and the read-only role.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    dpSetUp($this);
});

it('lists the preset templates of the wizard', function (): void {
    $presets = $this->getJson('/api/v1/down-payment-chains/lookups')->assertOk()->json('data.presets');

    expect(array_keys($presets))->toBe(['30_40_30', '50_50', 'caparra_balance'])
        ->and($presets['30_40_30'])->toHaveCount(3);
});

it('previews a plan with the split of every installment over the VAT rates', function (): void {
    $data = $this->postJson('/api/v1/down-payment-chains/preview-plan', ['source_type' => 'sales_order', 'source_id' => (string) $this->order->id, 'template' => '30_40_30'])
        ->assertOk()->json('data');

    expect($data['contract_net'])->toBe('1200.00')
        ->and($data['contract_gross'])->toBe('1440.00')
        ->and($data['planned_gross'])->toBe('1440.00')
        ->and($data['rounding_drift'])->toBe('0.00')
        ->and($data['installments'])->toHaveCount(3)
        ->and($data['installments'][0]['amount_net'])->toBe('360.00')
        ->and($data['installments'][0]['vat_breakdown'])->toHaveCount(2);

    expect(DownPaymentChain::withoutGlobalScopes()->count())->toBe(0);
});

it('opens a chain from a sales order with its plan and the totals of the contract', function (): void {
    $chain = dpOpenChain($this);

    expect($chain['status'])->toBe('active')
        ->and($chain['chain_number'])->toStartWith('DPC-')
        ->and($chain['jurisdiction'])->toBe('IT')
        ->and($chain['total_contract_net'])->toBe('1200.00')
        ->and($chain['total_contract_vat'])->toBe('240.00')
        ->and($chain['total_contract_gross'])->toBe('1440.00')
        ->and($chain['residual_order_gross'])->toBe('1440.00')
        ->and($chain['customer_name'])->toBe('Bianchi SpA')
        ->and($chain['installments'])->toHaveCount(3)
        ->and(collect($chain['vat_matrix'])->pluck('vat_rate')->all())->toBe(['10.00', '22.00']);

    $this->getJson('/api/v1/down-payment-chains?source_id='.$this->order->id)->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/down-payment-chains?country=CH')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/down-payment-chains?customer_id='.$this->customer->id.'&status=active')->assertOk()->assertJsonPath('meta.total', 1);
});

it('opens a chain without a plan and opens one only once per source', function (): void {
    $this->postJson('/api/v1/down-payment-chains/from-source', ['source_type' => 'sales_order', 'source_id' => (string) $this->order->id])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.installments', []);

    $this->postJson('/api/v1/down-payment-chains/from-source', ['source_type' => 'sales_order', 'source_id' => (string) $this->order->id, 'template' => '50_50'])
        ->assertStatus(409)->assertJsonPath('code', 'chain_exists_for_source');
});

it('builds a chain from a contract stated by the caller', function (): void {
    $id = (string) Str::uuid();
    $chain = $this->postJson('/api/v1/down-payment-chains/from-source', [
        'source_type' => 'contract',
        'source_id' => $id,
        'template' => '50_50',
        'contract' => ['customer_id' => (string) $this->customer->id, 'number' => 'CT-2026-7', 'currency' => 'EUR', 'lines' => [
            ['description' => 'Hardware', 'vat_rate' => '22', 'net' => '8000.00'],
            ['description' => 'Installation', 'vat_rate' => '10', 'net' => '2000.00'],
        ]],
    ])->assertCreated()->json('data');

    expect($chain['source_number'])->toBe('CT-2026-7')
        ->and($chain['total_contract_net'])->toBe('10000.00')
        ->and($chain['total_contract_gross'])->toBe('11960.00')
        ->and($chain['installments'][0]['amount_net'])->toBe('5000.00');
});

it('accepts a custom plan in percentage, fixed net and fixed gross', function (): void {
    $chain = dpOpenChain($this, ['template' => null, 'installments' => [
        ['trigger_type' => 'on_order_confirmation', 'calculation_mode' => 'percentage', 'percentage' => '25', 'instrument_type' => 'advance_invoice_immediate', 'milestone_label' => 'Kick-off'],
        ['trigger_type' => 'custom_date', 'due_date' => now()->addMonth()->toDateString(), 'calculation_mode' => 'fixed_net', 'amount' => '120.00', 'instrument_type' => 'advance_proforma_request'],
        ['trigger_type' => 'on_commissioning', 'calculation_mode' => 'fixed_gross', 'amount' => '288.00', 'instrument_type' => 'advance_invoice_immediate'],
    ]]);

    expect($chain['installments'])->toHaveCount(3)
        ->and($chain['installments'][0]['milestone_label'])->toBe('Kick-off')
        ->and($chain['installments'][0]['amount_net'])->toBe('300.00')
        ->and($chain['installments'][1]['amount_net'])->toBe('120.00')
        ->and($chain['installments'][2]['amount_gross'])->toBe('288.00');
});

it('refuses a plan that states more than the order holds', function (): void {
    $this->postJson('/api/v1/down-payment-chains/from-source', ['source_type' => 'sales_order', 'source_id' => (string) $this->order->id, 'installments' => [
        ['trigger_type' => 'on_order_confirmation', 'calculation_mode' => 'percentage', 'percentage' => '70', 'instrument_type' => 'advance_invoice_immediate'],
        ['trigger_type' => 'on_material_delivery', 'calculation_mode' => 'percentage', 'percentage' => '70', 'instrument_type' => 'advance_invoice_immediate'],
    ]])->assertUnprocessable()->assertJsonPath('code', 'plan_exceeds_contract');
});

it('validates the payload of the wizard', function (): void {
    $this->postJson('/api/v1/down-payment-chains/from-source', [])->assertUnprocessable()->assertJsonValidationErrors(['source_type', 'source_id']);
    $this->postJson('/api/v1/down-payment-chains/from-source', ['source_type' => 'sales_order', 'source_id' => (string) $this->order->id, 'installments' => [['trigger_type' => 'x', 'calculation_mode' => 'y', 'instrument_type' => 'z']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['installments.0.trigger_type', 'installments.0.calculation_mode', 'installments.0.instrument_type']);
    $this->postJson('/api/v1/down-payment-chains/from-source', ['source_type' => 'sales_order', 'source_id' => (string) $this->order->id, 'template' => 'unknown'])->assertUnprocessable()->assertJsonValidationErrors(['template']);
    $this->postJson('/api/v1/down-payment-chains/from-source', ['source_type' => 'contract', 'source_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['contract']);
});

it('keeps the companies apart', function (): void {
    $foreignOrder = billingOrder($this->otherTenant, $this->foreignCustomer);

    $this->postJson('/api/v1/down-payment-chains/from-source', ['source_type' => 'sales_order', 'source_id' => (string) $foreignOrder->id, 'template' => '50_50'])
        ->assertNotFound()->assertJsonPath('code', 'source_not_found');

    $chain = dpOpenChain($this);
    billingActor($this, 'accountant', $this->otherTenant);

    $this->getJson("/api/v1/down-payment-chains/{$chain['id']}")->assertNotFound();
    $this->getJson('/api/v1/down-payment-chains')->assertOk()->assertJsonPath('meta.total', 0);
});

it('lets a read-only role look but not write', function (): void {
    $chain = dpOpenChain($this);
    billingActor($this, 'accountant_readonly');

    $this->getJson('/api/v1/down-payment-chains')->assertOk();
    $this->getJson("/api/v1/down-payment-chains/{$chain['id']}")->assertOk();
    $this->postJson('/api/v1/down-payment-chains/from-source', ['source_type' => 'sales_order', 'source_id' => (string) $this->order->id, 'template' => '50_50'])->assertForbidden();
    dpEmit($this, $chain['id'], dpInstallment($chain, 1)['id'])->assertForbidden();
    $this->postJson("/api/v1/down-payment-chains/{$chain['id']}/generate-final-settlement")->assertForbidden();
});

it('cancels a chain that issued nothing and refuses to cancel one that did', function (): void {
    $chain = dpOpenChain($this);
    $this->postJson("/api/v1/down-payment-chains/{$chain['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    $order = billingOrder($this->tenant, $this->customer);
    $other = dpOpenChain($this, [], (string) $order->id);
    dpEmit($this, $other['id'], dpInstallment($other, 1)['id'])->assertOk();
    $this->postJson("/api/v1/down-payment-chains/{$other['id']}/cancel")->assertUnprocessable()->assertJsonPath('code', 'chain_has_documents');
});
