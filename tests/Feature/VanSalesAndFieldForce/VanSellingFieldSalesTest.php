<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\VanSalesAndFieldForce\Models\VanWarehouse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-25 08:00:00');
    $this->tenant = Tenant::create([
        'name' => 'VanFood Co '.Str::random(4),
        'legal_name' => 'VanFood Italia Srl',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);
    $this->owner = User::create([
        'name' => 'Owner '.Str::random(4),
        'email' => Str::random(8).'@mod233.example',
        'password' => bcrypt(Str::random(32)),
        'locale' => 'it',
    ]);
    $this->owner->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    app(TenantContext::class)->setTenant($this->tenant);
    Sanctum::actingAs($this->owner);
    $this->withHeaders(['X-Tenant-Id' => (string) $this->tenant->id]);

    $this->depot = Warehouse::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'MAIN',
        'name' => 'Central Depot',
        'is_primary' => true,
    ]);
    $this->product = Product::create([
        'tenant_id' => $this->tenant->id,
        'sku' => 'FORM-DOP',
        'barcode' => '08001234567893',
        'name' => 'Formaggio DOP',
        'unit_measure' => 'kg',
        'cost_price' => 12.00,
        'sale_price' => 25.00,
        'tax_rate' => 4.00,
        'track_inventory' => true,
        'is_active' => true,
    ]);
    app(StockService::class)->record((string) $this->product->id, 'in', 100.0, null, $this->depot, 12.00);

    $this->contact = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Bottega Alpina Srl',
        'country' => 'IT',
    ]);
});

afterEach(fn () => app(TenantContext::class)->clear());

it('manages mobile van warehouses, cold-chain alerts, sectional batch reservation, and Global Van Load DDT', function (): void {
    $van = $this->postJson('/api/v1/van-selling/vans', [
        'code' => 'MI01',
        'name' => 'Furgone Frigo Milano',
        'license_plate' => 'MI-94821-FS',
        'driver_name' => 'Marco Bellini',
        'depot_warehouse_id' => $this->depot->id,
        'has_cold_chain' => true,
        'min_temp_celsius' => 0.0,
        'max_temp_celsius' => 4.0,
        'current_temp_celsius' => 2.5,
        'printer_profile' => 'escpos_80mm',
        'printer_brand' => 'Bixolon',
    ])->assertCreated()->json('data');

    expect($van['invoice_sectional_code'])->toBe('FV-MI01')
        ->and($van['ddt_sectional_code'])->toBe('DDTV-MI01');

    // Out-of-range temperature triggers cold-chain alert
    $tempUpdated = $this->postJson("/api/v1/van-selling/vans/{$van['id']}/temperature", [
        'temp_celsius' => 7.8,
        'notes' => 'Door left open',
    ])->assertOk()->json('data');
    expect($tempUpdated['temp_alert_active'])->toBeTrue();

    // Reserve 10 offline invoice numbers
    $batch = $this->postJson("/api/v1/van-selling/vans/{$van['id']}/reserve-sectional-batch", [
        'document_type' => 'immediate_invoice',
        'count' => 10,
    ])->assertOk()->json('data');
    expect($batch['range_start'])->toBe(1)
        ->and($batch['range_end'])->toBe(10)
        ->and(count($batch['reserved_numbers']))->toBe(10);

    // Create Morning Load Manifest & emit Global Van Load DDT ("Tentata Vendita")
    $manifest = $this->postJson('/api/v1/van-selling/load-manifests', [
        'van_warehouse_id' => $van['id'],
        'load_date' => '2026-11-25',
        'route_code' => 'GIRO-MI',
        'departure_temp_celsius' => 2.2,
        'lines' => [
            ['product_id' => $this->product->id, 'quantity' => 20, 'lot_number' => 'LOT-2611', 'is_variable_weight' => true],
        ],
    ])->assertCreated()->json('data');

    $dispatched = $this->postJson("/api/v1/van-selling/load-manifests/{$manifest['id']}/issue-global-ddt")
        ->assertOk()
        ->json('data');

    expect($dispatched['status'])->toBe('ddt_issued')
        ->and($dispatched['global_ddt_number'])->toStartWith('DDTV-MI01/2026/')
        ->and($dispatched['global_ddt_causal'])->toBe('Tentata Vendita');
});

it('blocks unloading on overdue invoice, unblocks upon collection, records sale with GS1/empties, syncs offline, and settles EOD', function (): void {
    $van = $this->postJson('/api/v1/van-selling/vans', [
        'code' => 'TI01',
        'name' => 'Furgone Lugano',
        'license_plate' => 'TI-233480',
        'depot_warehouse_id' => $this->depot->id,
    ])->assertCreated()->json('data');

    $manifest = $this->postJson('/api/v1/van-selling/load-manifests', [
        'van_warehouse_id' => $van['id'],
        'load_date' => '2026-11-25',
        'lines' => [['product_id' => $this->product->id, 'quantity' => 15, 'lot_number' => 'LOT-A']],
    ])->assertCreated()->json('data');
    $this->postJson("/api/v1/van-selling/load-manifests/{$manifest['id']}/issue-global-ddt")->assertOk();

    // Create an overdue invoice for the customer -> blocks unloading
    $overdueInv = Invoice::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $this->contact->id,
        'invoice_number' => 'INV-OLD-001',
        'date' => '2026-10-01',
        'due_date' => '2026-10-31',
        'total_net' => 100.00,
        'total_tax' => 4.00,
        'total_gross' => 104.00,
        'total_paid' => 0.00,
        'payment_status' => 'overdue',
    ]);
    InvoiceItem::create([
        'invoice_id' => $overdueInv->id,
        'product_id' => $this->product->id,
        'description' => 'Formaggio DOP',
        'quantity' => 4,
        'unit_price' => 25.00,
        'tax_rate' => 4.00,
        'line_total' => 100.00,
    ]);

    $routePlan = $this->postJson('/api/v1/van-selling/route-plans', [
        'van_warehouse_id' => $van['id'],
        'van_load_manifest_id' => $manifest['id'],
        'route_code' => 'GIRO-TI',
        'name' => 'Giro Ticino',
        'plan_date' => '2026-11-25',
        'stops' => [['sequence' => 1, 'contact_id' => $this->contact->id]],
    ])->assertCreated()->json('data');

    // Dossier shows blocked credit + Fast Reorder (last 5 purchases)
    $dossier = $this->getJson("/api/v1/van-selling/customers/{$this->contact->id}/field-dossier")
        ->assertOk()
        ->json('data');
    expect($dossier['credit']['is_blocked'])->toBeTrue()
        ->and(count($dossier['fast_reorder_last_5']))->toBe(1);

    // Attempted sale without collecting overdue is blocked
    $blockedVisit = $this->postJson('/api/v1/van-selling/visits', [
        'field_route_plan_id' => $routePlan['id'],
        'contact_id' => $this->contact->id,
        'outcome_status' => 'completed_sale',
        'sale_lines' => [['product_id' => $this->product->id, 'quantity' => 2]],
    ])->assertCreated()->json('data');
    expect($blockedVisit['outcome_status'])->toBe('credit_blocked');

    // Collect overdue invoice -> unblocks customer
    $unblock = $this->postJson("/api/v1/van-selling/customers/{$this->contact->id}/unblock-credit", [
        'invoice_id' => $overdueInv->id,
        'amount' => 104.00,
        'payment_method' => 'twint',
    ])->assertOk()->json('data');
    expect($unblock['unblocked'])->toBeTrue();

    // Offline delta sync with idempotency key
    $syncResult = $this->postJson('/api/v1/van-selling/offline-sync', [
        'van_warehouse_id' => $van['id'],
        'client_sync_version' => 1,
        'visits' => [[
            'field_route_plan_id' => $routePlan['id'],
            'contact_id' => $this->contact->id,
            'outcome_status' => 'completed_sale',
            'document_type' => 'immediate_invoice',
            'sale_lines' => [['product_id' => $this->product->id, 'quantity' => 5.0, 'lot_number' => 'LOT-A']],
            'empties_movements' => [['item_code' => 'EMP-CASSA-151', 'item_name' => 'Cassa Mod 151', 'delivered_qty' => 2, 'returned_qty' => 1, 'unit_deposit' => 12.0]],
            'collections' => [['method' => 'cash', 'amount' => 130.00]],
            'client_idempotency_key' => 'OFFLINE-KEY-001',
        ]],
    ])->assertOk()->json('data');
    expect($syncResult['processed_count'])->toBe(1)
        ->and($syncResult['processed_visits'][0]['definitive_document_number'])->toStartWith('FV-TI01/2026/');

    $visitId = $syncResult['processed_visits'][0]['visit_id'];
    $printPayload = $this->getJson("/api/v1/van-selling/visits/{$visitId}/thermal-print")->assertOk()->json('data');
    expect($printPayload['zpl_commands'])->toContain('^XA');

    // End-of-Day Settlement: 15 loaded - 5 sold = 10 remaining (6 keep onboard, 4 unload to central depot)
    $preview = $this->postJson('/api/v1/van-selling/settlements/preview', [
        'van_warehouse_id' => $van['id'],
        'settlement_date' => '2026-11-25',
    ])->assertOk()->json('data');
    expect((float) $preview['stock_reconciliation_lines'][0]['remaining_qty'])->toBe(10.0);

    $lines = $preview['stock_reconciliation_lines'];
    $lines[0]['keep_onboard_qty'] = 6.0;
    $lines[0]['unload_central_qty'] = 4.0;

    $settlement = $this->postJson('/api/v1/van-selling/settlements', [
        'van_warehouse_id' => $van['id'],
        'settlement_date' => '2026-11-25',
        'stock_reconciliation_lines' => $lines,
        'opening_cash_float' => 100.0,
        'counted_cash_remittance' => 230.0,
    ])->assertCreated()->json('data');

    expect($settlement['status'])->toBe('reconciled')
        ->and((float) $settlement['total_keep_onboard_qty'])->toBe(6.0)
        ->and((float) $settlement['total_unload_central_qty'])->toBe(4.0)
        ->and($settlement['central_warehouse_unloaded'])->toBeTrue();
});

it('isolates van warehouses across tenants', function (): void {
    $otherTenant = Tenant::create([
        'name' => 'Other Van Co '.Str::random(4),
        'legal_name' => 'Other Van Co Srl',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);
    $otherUser = User::create([
        'name' => 'Other '.Str::random(4),
        'email' => Str::random(8).'@other-mod233.example',
        'password' => bcrypt(Str::random(32)),
        'locale' => 'it',
    ]);
    $otherUser->tenants()->attach($otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);

    $van = VanWarehouse::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'VAN-ISO',
        'name' => 'Isolated Van',
        'license_plate' => 'RM-00001-AA',
        'invoice_sectional_code' => 'FV-ISO',
        'ddt_sectional_code' => 'DDTV-ISO',
        'receipt_sectional_code' => 'RTV-ISO',
    ]);

    app(TenantContext::class)->setTenant($otherTenant);
    Sanctum::actingAs($otherUser);
    $this->withHeaders(['X-Tenant-Id' => (string) $otherTenant->id]);

    $this->getJson("/api/v1/van-selling/vans/{$van->id}")->assertNotFound();
    $this->getJson('/api/v1/van-selling/vans')->assertOk()->assertJsonCount(0, 'data');
});
