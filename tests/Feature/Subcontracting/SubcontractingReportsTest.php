<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Subcontracting\Models\SubcontractMaterialLedgerEntry;

require_once __DIR__.'/SubcontractingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    sctSetUp($this);
});

it('shows the stock held by partners with aging and the statutory deadline status', function (): void {
    $recent = sctDispatchAll($this, sctPassiveOrder($this, ['planned_quantity' => '10']), ['date' => now()->subDays(10)->toDateString()]);
    $expiring = sctDispatchAll($this, sctPassiveOrder($this, ['planned_quantity' => '5']), ['date' => now()->subYear()->addDays(10)->toDateString()]);
    $overdue = sctDispatchAll($this, sctPassiveOrder($this, ['planned_quantity' => '2']), ['date' => now()->subMonths(13)->toDateString()]);
    $active = sctActiveOrder($this, ['components' => [['component_product_id' => (string) $this->steel->id, 'required_qty_per_unit' => '1']]]);
    $this->postJson("/api/v1/subcontracting/orders/{$active->id}/receive-customer-materials")->assertOk();

    $response = $this->getJson('/api/v1/subcontracting/dashboard')->assertOk();
    $kpis = $response->json('data.kpis');

    expect($kpis['active_passive_orders'])->toBe(3)
        ->and($kpis['active_customer_orders'])->toBe(1)
        ->and($kpis['expiring_30d'])->toBe(2) // the steel and the paint lines of the expiring order
        ->and($kpis['overdue_deadlines'])->toBe(2)
        ->and($kpis['units_held'])->toBe('52.5000');

    $rows = collect($response->json('data.rows'));
    $byOrder = fn (string $id, string $product) => $rows->first(fn (array $row): bool => $row['order_id'] === $id && $row['product_id'] === (string) $product);

    expect($byOrder((string) $recent->id, (string) $this->steel->id)['deadline_status'])->toBe('ok')
        ->and($byOrder((string) $recent->id, (string) $this->steel->id)['aging_days'])->toBe(10)
        ->and($byOrder((string) $expiring->id, (string) $this->steel->id)['deadline_status'])->toBe('warning')
        ->and($byOrder((string) $overdue->id, (string) $this->steel->id)['deadline_status'])->toBe('expired')
        ->and($byOrder((string) $overdue->id, (string) $this->steel->id)['days_to_deadline'])->toBeLessThan(0)
        ->and($response->json('data.by_partner'))->toHaveCount(2);

    $filtered = $this->getJson('/api/v1/subcontracting/dashboard?direction=active')->json('data.rows');
    expect($filtered)->toHaveCount(1)->and($filtered[0]['direction'])->toBe('active');

    $expired = $this->getJson('/api/v1/subcontracting/dashboard?deadline_status=expired')->json('data.rows');
    expect($expired)->toHaveCount(2);
});

it('lists the statutory register with the one-year countdown and filters it', function (): void {
    $order = sctDispatchAll($this, sctPassiveOrder($this), ['date' => now()->subMonths(13)->toDateString()]);
    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/receive", ['good_quantity' => '4', 'vendor_ddt_number' => 'TZ-1'])->assertOk();

    $response = $this->getJson('/api/v1/subcontracting/register?per_page=50')->assertOk();
    $rows = collect($response->json('data'));

    expect($response->json('meta.total'))->toBe(4)
        ->and($rows->pluck('movement_type')->unique()->sort()->values()->all())->toBe(['inbound_consumption_backflush', 'outbound_dispatch'])
        ->and($rows->every(fn (array $row): bool => $row['deadline_status'] === 'expired'))->toBeTrue()
        ->and($rows->first(fn (array $row): bool => $row['movement_type'] === 'outbound_dispatch')['ddt_number'])->toStartWith('DDT-CL-');

    $dispatches = $this->getJson('/api/v1/subcontracting/register?movement_type=outbound_dispatch')->json('data');
    expect($dispatches)->toHaveCount(2);

    $none = $this->getJson('/api/v1/subcontracting/register?direction=active')->json('meta.total');
    expect($none)->toBe(0);

    $this->getJson('/api/v1/subcontracting/register?from=2030-01-01&to=2029-01-01')->assertUnprocessable();
});

it('closes the deadline of a line once nothing is left at the partner', function (): void {
    $order = sctDispatchAll($this, sctPassiveOrder($this), ['date' => now()->subMonths(13)->toDateString()]);
    $this->postJson("/api/v1/subcontracting/orders/{$order->id}/receive", ['good_quantity' => '10'])->assertOk();

    $rows = collect($this->getJson('/api/v1/subcontracting/register?per_page=50')->json('data'));

    expect($rows->every(fn (array $row): bool => $row['deadline_status'] === 'closed'))->toBeTrue()
        ->and($this->getJson('/api/v1/subcontracting/dashboard')->json('data.kpis.units_held'))->toBe('0.0000');
});

it('exports the register as CSV and Excel for who may export it', function (): void {
    $order = sctDispatchAll($this, sctPassiveOrder($this));
    $ddt = SubcontractMaterialLedgerEntry::query()->where('subcontracting_order_id', $order->id)->value('ddt_number');

    $csv = $this->get('/api/v1/subcontracting/register/export/csv')->assertOk();
    expect($csv->headers->get('Content-Type'))->toContain('text/csv')
        ->and($csv->getContent())->toContain((string) $ddt);

    $this->get('/api/v1/subcontracting/register/export/xlsx')->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->get('/api/v1/subcontracting/register/export/odt')->assertNotFound();

    $planner = tfrUserWithRole((string) $this->tenant->id, 'production');
    Sanctum::actingAs($planner);
    $this->get('/api/v1/subcontracting/register/export/csv')->assertForbidden();
    $this->getJson('/api/v1/subcontracting/register')->assertOk();
});

it('summarizes the TPP and TPA jobs with the re-import balance', function (): void {
    $tpp = sctDispatchAll($this, sctPassiveOrder($this, ['customs_regime' => 'tpp_outward_processing', 'expected_return_date' => now()->subDays(3)->toDateString()]));
    $this->postJson("/api/v1/subcontracting/orders/{$tpp->id}/receive", ['good_quantity' => '6'])->assertOk();
    $tpa = sctActiveOrder($this, ['customs_regime' => 'tpa_inward_processing']);
    sctPassiveOrder($this);

    $response = $this->getJson('/api/v1/subcontracting/cross-border')->assertOk();
    $orders = collect($response->json('data.orders'));

    expect($orders)->toHaveCount(2)
        ->and($orders->firstWhere('order_id', (string) $tpp->id)['goods_back_qty'])->toBe('6.0000')
        ->and($orders->firstWhere('order_id', (string) $tpp->id)['balance_qty'])->toBe('4.0000')
        ->and($orders->firstWhere('order_id', (string) $tpp->id)['expired'])->toBeTrue()
        ->and($orders->firstWhere('order_id', (string) $tpa->id)['customs_regime'])->toBe('tpa_inward_processing')
        ->and(collect($response->json('data.regimes'))->pluck('customs_regime')->sort()->values()->all())->toBe(['tpa_inward_processing', 'tpp_outward_processing']);

    $only = $this->getJson('/api/v1/subcontracting/cross-border?customs_regime=tpa_inward_processing')->json('data.orders');
    expect($only)->toHaveCount(1);

    $this->postJson('/api/v1/subcontracting/orders', sctOrderPayload($this, ['customs_regime' => 'tpp_outward_processing', 'customs_declaration_id' => (string) Str::uuid()]))
        ->assertUnprocessable()->assertJsonPath('code', 'customs_declaration_not_found');
});

it('never changes or deletes a ledger line', function (): void {
    $order = sctDispatchAll($this, sctPassiveOrder($this));
    $entry = SubcontractMaterialLedgerEntry::query()->where('subcontracting_order_id', $order->id)->firstOrFail();

    expect(fn () => $entry->update(['dispatched_qty' => '1']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});

it('lists the orders with filters and serves the lookups', function (): void {
    sctPassiveOrder($this);
    sctActiveOrder($this);

    expect($this->getJson('/api/v1/subcontracting/orders')->json('meta.total'))->toBe(2)
        ->and($this->getJson('/api/v1/subcontracting/orders?direction=active')->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/subcontracting/orders?status=draft')->json('meta.total'))->toBe(0)
        ->and($this->getJson('/api/v1/subcontracting/orders?search=cliente')->json('meta.total'))->toBe(1);

    $lookups = $this->getJson('/api/v1/subcontracting/lookups')->assertOk()->json('data');
    expect($lookups['directions'])->toBe(['passive', 'active'])
        ->and($lookups['customs_regimes'])->toBe(['none', 'tpp_outward_processing', 'tpa_inward_processing']);
});
