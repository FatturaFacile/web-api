<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;
use Modules\Logistics\Services\WarehouseStockService;
use Modules\Procurement\Models\Supplier;
use Modules\Subcontracting\Models\SubcontractingOrder;

/**
 * A company with an owner signed in, a main warehouse holding 500 steel (cost 10) and 300 paint (cost 4), a vendor, a customer
 * and the finished product, plus a second company to prove the isolation.
 */
function sctSetUp(object $test, string $role = 'owner'): void
{
    ['tenant' => $test->tenant, 'user' => $test->user] = tfrTenant('Officina Terzi', $role);
    ['tenant' => $test->otherTenant, 'user' => $test->otherUser] = tfrTenant('Altra Officina', 'owner');

    app(TenantContext::class)->setTenant($test->tenant);
    Sanctum::actingAs($test->user);
    $test->withHeader('X-Tenant-Id', (string) $test->tenant->id);

    $test->main = tfrWarehouse((string) $test->tenant->id, 'MAIN', ['is_primary' => true]);
    $test->vendor = Supplier::factory()->create(['tenant_id' => $test->tenant->id]);
    $test->customer = tfrCustomer((string) $test->tenant->id, 'Cliente Meccanica Srl');
    $test->finished = tfrProduct((string) $test->tenant->id, 'FIN-GALV', 0, 100);
    $test->steel = tfrProduct((string) $test->tenant->id, 'RAW-STEEL', 10);
    $test->paint = tfrProduct((string) $test->tenant->id, 'RAW-PAINT', 4);
    $test->stock = app(WarehouseStockService::class);
    $test->stock->receiveOpeningStock((string) $test->main->id, (string) $test->steel->id, 500, 10);
    $test->stock->receiveOpeningStock((string) $test->main->id, (string) $test->paint->id, 300, 4);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sctOrderPayload(object $test, array $overrides = []): array
{
    return array_merge([
        'direction' => 'passive',
        'subcontracting_type' => 'full_bom',
        'partner_id' => (string) $test->vendor->id,
        'output_product_id' => (string) $test->finished->id,
        'planned_quantity' => '10',
        'unit_service_cost' => '5',
        'currency' => 'EUR',
        'expected_return_date' => now()->addDays(30)->toDateString(),
        'components' => [
            ['component_product_id' => (string) $test->steel->id, 'required_qty_per_unit' => '2', 'unit_of_measure' => 'KG'],
            ['component_product_id' => (string) $test->paint->id, 'required_qty_per_unit' => '0.5', 'unit_of_measure' => 'L'],
        ],
    ], $overrides);
}

/**
 * Creates a passive order and confirms it.
 *
 * @param  array<string, mixed>  $overrides
 */
function sctPassiveOrder(object $test, array $overrides = [], bool $confirm = true): SubcontractingOrder
{
    $response = $test->postJson('/api/v1/subcontracting/orders', sctOrderPayload($test, $overrides))->assertCreated();
    $order = SubcontractingOrder::query()->findOrFail($response->json('data.id'));

    if ($confirm) {
        $test->postJson("/api/v1/subcontracting/orders/{$order->id}/confirm")->assertOk();
    }

    return $order->refresh();
}

/**
 * Creates and confirms an active order (the customer's material, processed here).
 *
 * @param  array<string, mixed>  $overrides
 */
function sctActiveOrder(object $test, array $overrides = []): SubcontractingOrder
{
    return sctPassiveOrder($test, array_merge([
        'direction' => 'active',
        'partner_id' => (string) $test->customer->id,
        'customs_regime' => 'none',
    ], $overrides));
}

/**
 * Dispatches everything planned of a passive order to the vendor.
 *
 * @param  array<string, mixed>  $payload
 */
function sctDispatchAll(object $test, SubcontractingOrder $order, array $payload = []): SubcontractingOrder
{
    $test->postJson("/api/v1/subcontracting/orders/{$order->id}/dispatch", $payload)->assertOk();

    return $order->refresh();
}
