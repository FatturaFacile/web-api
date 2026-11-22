<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\SupplierContract;

require_once __DIR__.'/ProcurementEnterpriseFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = procTenant('Quadro Acquisti');
    $this->buyer = procMember($this->tenant, 'admin');
    procActAs($this->buyer, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * @param  array<string, mixed>  $overrides
 */
function blanketContract(string $supplierId, array $overrides = []): string
{
    $id = (string) test()->postJson('/api/v1/procurement/contracts', [
        'supplier_id' => $supplierId,
        'contract_number' => 'BPA-'.strtoupper(substr(md5((string) microtime(true)), 0, 6)),
        'title' => 'Blanket purchase agreement',
        'contract_type' => 'blanket',
        'total_committed_amount' => 1000,
        'minimum_spend_amount' => 600,
        'alert_threshold_percent' => 80,
        'currency' => 'CHF',
        'starts_at' => now()->subMonth()->toDateString(),
        'ends_at' => now()->addYear()->toDateString(),
        ...$overrides,
    ])->assertCreated()->json('id');

    SupplierContract::query()->whereKey($id)->update(['status' => 'active']);

    return $id;
}

test('the contract keeps the minimum spend, the alert threshold and the currency and accepts the new statuses', function (): void {
    $contractId = blanketContract(procSupplier());

    $this->getJson("/api/v1/procurement/contracts/{$contractId}")->assertOk()
        ->assertJsonPath('minimum_spend_amount', 600)
        ->assertJsonPath('alert_threshold_percent', 80)
        ->assertJsonPath('currency', 'CHF');

    $this->putJson("/api/v1/procurement/contracts/{$contractId}", ['status' => 'suspended'])->assertOk()->assertJsonPath('status', 'suspended');
    $this->putJson("/api/v1/procurement/contracts/{$contractId}", ['status' => 'closed'])->assertOk()->assertJsonPath('status', 'closed');
    $this->putJson("/api/v1/procurement/contracts/{$contractId}", ['status' => 'unknown'])->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('the balance follows the call-off orders and ignores cancelled ones', function (): void {
    $contractId = blanketContract(procSupplier());
    $lineId = $this->postJson("/api/v1/procurement/contracts/{$contractId}/lines", ['description' => 'Bolt M8', 'unit_price' => '1.00', 'committed_quantity' => '1000', 'price_valid_from' => now()->subMonth()->toDateString()])
        ->assertCreated()->json('data.id');

    $first = $this->postJson("/api/v1/procurement/contracts/{$contractId}/call-offs", ['expected_delivery_date' => now()->addDays(3)->toDateString(), 'lines' => [['supplier_contract_line_id' => $lineId, 'quantity_ordered' => '300']]])
        ->assertCreated()->json('id');
    $second = $this->postJson("/api/v1/procurement/contracts/{$contractId}/call-offs", ['expected_delivery_date' => now()->addDays(3)->toDateString(), 'lines' => [['supplier_contract_line_id' => $lineId, 'quantity_ordered' => '200']]])
        ->assertCreated()->json('id');

    $balance = $this->getJson("/api/v1/procurement/contracts/{$contractId}/balance")->assertOk()->json('data');

    expect($balance['currency'])->toBe('CHF')
        ->and($balance['ceiling_amount'])->toBe('1000.00')
        ->and($balance['minimum_spend_amount'])->toBe('600.00')
        ->and($balance['ordered_amount'])->toBe('500.00')
        ->and($balance['remaining_ceiling'])->toBe('500.00')
        ->and($balance['ceiling_used_percent'])->toBe('50.00')
        ->and($balance['minimum_shortfall'])->toBe('100.00')
        ->and($balance['lines'][0]['committed_quantity'])->toBe('1000.0000')
        ->and($balance['lines'][0]['ordered_amount'])->toBe('500.00')
        ->and(array_column($balance['alerts'], 'code'))->toBe([]);

    $this->postJson("/api/v1/procurement/purchase-orders/{$second}/cancel")->assertOk();

    $after = $this->getJson("/api/v1/procurement/contracts/{$contractId}/balance")->assertOk()->json('data');
    expect($after['ordered_amount'])->toBe('300.00')->and($after['minimum_shortfall'])->toBe('300.00');
    expect(PurchaseOrder::find($first)->supplier_contract_id)->toBe($contractId);
});

test('the balance raises the ceiling and minimum spend alerts', function (): void {
    $contractId = blanketContract(procSupplier(), ['total_committed_amount' => 500, 'minimum_spend_amount' => 400, 'ends_at' => now()->addDays(30)->toDateString()]);
    $lineId = $this->postJson("/api/v1/procurement/contracts/{$contractId}/lines", ['description' => 'Bolt M8', 'unit_price' => '1.00', 'price_valid_from' => now()->subMonth()->toDateString()])
        ->assertCreated()->json('data.id');

    $codes = fn (): array => array_column($this->getJson("/api/v1/procurement/contracts/{$contractId}/balance")->assertOk()->json('data.alerts'), 'code');

    // Nothing ordered and 30 days left: the minimum spend is at risk.
    expect($codes())->toBe(['minimum_at_risk']);

    // 420 of a ceiling of 500 is above the 80% threshold; the minimum of 400 is met.
    $this->postJson("/api/v1/procurement/contracts/{$contractId}/call-offs", ['expected_delivery_date' => now()->addDays(3)->toDateString(), 'lines' => [['supplier_contract_line_id' => $lineId, 'quantity_ordered' => '420']]])->assertCreated();
    expect($codes())->toBe(['ceiling_near']);

    // A different tenant never sees the balance.
    $other = procTenant('Altro Quadro');
    procActAs(procMember($other, 'admin'), $other);
    $this->getJson("/api/v1/procurement/contracts/{$contractId}/balance")->assertNotFound();
});
