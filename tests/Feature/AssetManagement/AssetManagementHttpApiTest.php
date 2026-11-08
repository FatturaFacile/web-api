<?php

declare(strict_types=1);

namespace Tests\Feature\AssetManagement;

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceLine;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\AssetLocation;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Models\MaintenancePlan;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;
use Modules\Projects\Models\Project;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    $this->tenant = Tenant::create(['name' => 'Cespiti', 'legal_name' => 'Cespiti SpA', 'vat_number' => 'IT33333333333', 'country' => 'IT']);
    $this->otherTenant = Tenant::create(['name' => 'Altra', 'legal_name' => 'Altra SpA', 'vat_number' => 'IT44444444444', 'country' => 'IT']);
    fiscalCalendar($this->tenant, (int) now()->year);
});

/**
 * A member of the tenant with the role, signed in for the next requests.
 */
function eamApiActor(string $role, ?Tenant $tenant = null): User
{
    $tenant ??= test()->tenant;
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);

    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);

    return $user;
}

/**
 * A category of the tenant through the API (straight line over 5 years, no salvage).
 */
function eamApiCategory(array $overrides = []): string
{
    return (string) test()->postJson('/api/v1/eam/categories', [
        'code' => 'MAC-'.strtoupper(Str::random(4)),
        'name' => 'Machinery',
        'useful_life_years' => 5,
        'salvage_value_percent' => 0,
        ...$overrides,
    ])->assertCreated()->json('data.id');
}

/**
 * A registered asset through the API.
 */
function eamApiAsset(string $categoryId, array $overrides = []): string
{
    return (string) test()->postJson('/api/v1/eam/assets', [
        'name' => 'CNC lathe',
        'category_id' => $categoryId,
        'acquisition_cost' => '12000.00',
        ...$overrides,
    ])->assertCreated()->json('data.id');
}

/**
 * A registered and capitalized asset through the API.
 */
function eamApiCapitalizedAsset(string $categoryId, array $overrides = []): string
{
    $id = eamApiAsset($categoryId, $overrides);
    test()->postJson("/api/v1/eam/assets/{$id}/capitalize", ['capitalization_date' => now()->toDateString()])->assertOk();

    return $id;
}

test('catalog endpoints create, read and update categories, classes and locations', function (): void {
    eamApiActor('admin');

    $categoryId = eamApiCategory(['fiscal_rate_percent' => '12.5', 'rate_reference' => 'Configured by the accountant', 'pro_rata_convention' => 'half_year']);
    $this->getJson('/api/v1/eam/categories')->assertOk()->assertJsonPath('data.0.id', $categoryId);
    $this->getJson("/api/v1/eam/categories/{$categoryId}")->assertOk()->assertJsonPath('data.fiscal_rate_percent', '12.5000')->assertJsonPath('data.pro_rata_convention', 'half_year');
    $this->putJson("/api/v1/eam/categories/{$categoryId}", ['name' => 'Plant', 'useful_life_years' => 8])->assertOk()->assertJsonPath('data.name', 'Plant')->assertJsonPath('data.useful_life_years', 8);

    $this->postJson('/api/v1/eam/classes', ['code' => 'CRIT', 'name' => 'Critical', 'criticality' => 'high'])->assertCreated()->assertJsonPath('data.criticality', 'high');
    $this->getJson('/api/v1/eam/classes')->assertOk()->assertJsonCount(1, 'data');

    $locationId = $this->postJson('/api/v1/eam/locations', ['code' => 'HQ', 'name' => 'Headquarters'])->assertCreated()->json('data.id');
    $this->putJson("/api/v1/eam/locations/{$locationId}", ['city' => 'Milano'])->assertOk()->assertJsonPath('data.city', 'Milano');
    $this->getJson('/api/v1/eam/locations')->assertOk()->assertJsonCount(1, 'data');
});

test('catalog validation refuses duplicate codes, invalid methods and foreign parents', function (): void {
    eamApiActor('admin');
    $foreignParent = AssetCategory::factory()->create(['tenant_id' => $this->otherTenant->id]);

    eamApiCategory(['code' => 'DUP']);
    $this->postJson('/api/v1/eam/categories', ['code' => 'DUP', 'name' => 'Again', 'depreciation_method' => 'magic', 'parent_id' => $foreignParent->id, 'fiscal_rate_percent' => 0])
        ->assertUnprocessable()->assertJsonValidationErrors(['code', 'depreciation_method', 'parent_id', 'fiscal_rate_percent']);
    $this->postJson('/api/v1/eam/locations', [])->assertUnprocessable()->assertJsonValidationErrors(['code', 'name']);
    $this->postJson('/api/v1/eam/classes', ['code' => 'X'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
});

test('asset CRUD lists, filters, shows, updates and deletes only registered assets', function (): void {
    eamApiActor('admin');
    $categoryId = eamApiCategory();
    $assetId = eamApiAsset($categoryId, ['serial_number' => 'SN-42', 'warranty_expiry_date' => now()->addDays(10)->toDateString()]);

    $this->getJson('/api/v1/eam/assets?search=SN-42')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.acquisition_cost', '12000.00');
    $this->getJson('/api/v1/eam/assets?status=disposed')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/eam/assets/{$assetId}")->assertOk()->assertJsonPath('data.status', 'registered');
    $this->patchJson("/api/v1/eam/assets/{$assetId}", ['name' => 'CNC lathe 2', 'insurance_provider' => 'Assicura', 'acquisition_cost' => '1'])
        ->assertOk()->assertJsonPath('data.name', 'CNC lathe 2')->assertJsonPath('data.insurance_provider', 'Assicura')->assertJsonPath('data.acquisition_cost', '12000.00');

    $capitalized = eamApiCapitalizedAsset($categoryId);
    $this->deleteJson("/api/v1/eam/assets/{$capitalized}")->assertUnprocessable();
    $this->deleteJson("/api/v1/eam/assets/{$assetId}")->assertNoContent();
    $this->getJson("/api/v1/eam/assets/{$assetId}")->assertNotFound();
});

test('asset registration validates money, references and duplicate numbers', function (): void {
    eamApiActor('admin');
    $categoryId = eamApiCategory();
    $foreignLocation = AssetLocation::factory()->create(['tenant_id' => $this->otherTenant->id]);
    eamApiAsset($categoryId, ['asset_number' => 'AST-1']);

    $this->postJson('/api/v1/eam/assets', ['name' => 'X', 'category_id' => $categoryId, 'acquisition_cost' => '10.123', 'location_id' => $foreignLocation->id, 'asset_number' => 'AST-1', 'depreciation_method' => 'custom'])
        ->assertUnprocessable()->assertJsonValidationErrors(['acquisition_cost', 'location_id', 'asset_number', 'depreciation_method']);
    $this->postJson('/api/v1/eam/assets', ['name' => 'X', 'category_id' => $categoryId, 'acquisition_cost' => '100', 'salvage_value' => '200'])
        ->assertUnprocessable()->assertJsonValidationErrors('salvage_value');
});

test('lifecycle endpoints capitalize, commission, transfer, valuate, reverse impairment and dispose', function (): void {
    eamApiActor('admin');
    $categoryId = eamApiCategory();
    $from = AssetLocation::factory()->create(['tenant_id' => $this->tenant->id]);
    $to = AssetLocation::factory()->create(['tenant_id' => $this->tenant->id]);
    $assetId = eamApiAsset($categoryId, ['location_id' => $from->id]);

    $this->postJson("/api/v1/eam/assets/{$assetId}/capitalize", ['capitalization_date' => now()->toDateString(), 'pro_rata_convention' => 'monthly'])
        ->assertOk()->assertJsonPath('data.status', 'capitalized')->assertJsonPath('data.capitalized_cost', '12000.00');
    $this->postJson("/api/v1/eam/assets/{$assetId}/capitalize")->assertUnprocessable();
    $this->postJson("/api/v1/eam/assets/{$assetId}/commission")->assertOk()->assertJsonPath('data.status', 'in_service');

    $this->postJson("/api/v1/eam/assets/{$assetId}/transfer", [])->assertUnprocessable()->assertJsonValidationErrors('to_location_id');
    $this->postJson("/api/v1/eam/assets/{$assetId}/transfer", ['to_location_id' => $to->id, 'reason' => 'Moved'])
        ->assertCreated()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.from_location_id', $from->id);
    expect(FixedAsset::find($assetId)->location_id)->toBe($to->id);

    $this->postJson("/api/v1/eam/assets/{$assetId}/valuate", ['valuation_type' => 'impairment', 'fair_value' => '13000'])->assertUnprocessable();
    $this->postJson("/api/v1/eam/assets/{$assetId}/valuate", ['valuation_type' => 'impairment', 'fair_value' => '9000.00'])
        ->assertCreated()->assertJsonPath('data.impairment_loss_amount', '3000.00')->assertJsonPath('data.carrying_amount_after', '9000.00');
    $this->postJson("/api/v1/eam/assets/{$assetId}/reverse-impairment", ['recoverable_amount' => '11000'])
        ->assertCreated()->assertJsonPath('data.adjustment_amount', '2000.00');

    $this->postJson("/api/v1/eam/assets/{$assetId}/dispose", ['disposal_type' => 'sale', 'disposed_percentage' => 150])->assertUnprocessable()->assertJsonValidationErrors('disposed_percentage');
    $this->postJson("/api/v1/eam/assets/{$assetId}/dispose", ['disposal_type' => 'sale', 'disposed_percentage' => 25, 'proceeds_amount' => '3000'])
        ->assertCreated()->assertJsonPath('data.is_partial', true)->assertJsonPath('data.carrying_amount_at_disposal', '2750.00')->assertJsonPath('data.gain_loss_amount', '250.00');
    $this->getJson("/api/v1/eam/assets/{$assetId}")->assertJsonPath('data.carrying_amount', '8250.00')->assertJsonPath('data.status', 'impaired');

    $this->postJson("/api/v1/eam/assets/{$assetId}/dispose", ['disposal_type' => 'scrap'])->assertCreated()->assertJsonPath('data.gain_loss_amount', '-8250.00');
    $this->getJson("/api/v1/eam/assets/{$assetId}")->assertJsonPath('data.status', 'disposed');
    $this->postJson("/api/v1/eam/assets/{$assetId}/transfer", ['to_location_id' => $from->id])->assertUnprocessable();

    $history = $this->getJson("/api/v1/eam/assets/{$assetId}/history")->assertOk();
    expect($history->json('data.transfers'))->toHaveCount(1)
        ->and($history->json('data.valuations'))->toHaveCount(2)
        ->and($history->json('data.disposals'))->toHaveCount(2);
});

test('revaluation splits the surplus and a locked asset cannot be changed', function (): void {
    eamApiActor('admin');
    $assetId = eamApiCapitalizedAsset(eamApiCategory());

    $this->postJson("/api/v1/eam/assets/{$assetId}/valuate", ['valuation_type' => 'revaluation', 'fair_value' => '15000'])
        ->assertCreated()->assertJsonPath('data.revaluation_reserve_delta', '3000.00');
    $this->postJson("/api/v1/eam/assets/{$assetId}/valuate", ['valuation_type' => 'revaluation', 'fair_value' => '10000'])
        ->assertCreated()->assertJsonPath('data.revaluation_reserve_delta', '-3000.00');
    expect(FixedAsset::find($assetId))->impairment_loss_total->toBe('2000.00')->revaluation_surplus_total->toBe('0.00');

    FixedAsset::whereKey($assetId)->update(['is_locked' => true]);
    $this->patchJson("/api/v1/eam/assets/{$assetId}", ['name' => 'Locked'])->assertForbidden();
    $this->postJson("/api/v1/eam/assets/{$assetId}/dispose", ['disposal_type' => 'scrap'])->assertForbidden();
});

test('acquisition from a passive invoice line registers and capitalizes once', function (): void {
    eamApiActor('admin');
    $categoryId = eamApiCategory();
    $supplier = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Fornitore', 'type' => 'supplier', 'country' => 'IT']);
    $invoice = PassiveInvoice::create(['tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'FP-1', 'date' => now()->toDateString(), 'total_amount' => 6100, 'currency' => 'EUR']);
    $line = PassiveInvoiceLine::create(['tenant_id' => $this->tenant->id, 'passive_invoice_id' => $invoice->id, 'line_number' => 1, 'description' => 'Forklift', 'quantity' => 1, 'unit_price' => 5000, 'line_total' => 5000, 'tax_rate' => 22]);

    $this->postJson('/api/v1/eam/assets/acquire', ['source_type' => 'passive_invoice_line', 'source_id' => $line->id, 'category_id' => $categoryId, 'capitalize' => true])
        ->assertCreated()->assertJsonPath('data.status', 'capitalized')->assertJsonPath('data.capitalized_cost', '5000.00')
        ->assertJsonPath('data.purchase_invoice_line_id', $line->id)->assertJsonPath('data.supplier_id', $supplier->id);
    $this->postJson('/api/v1/eam/assets/acquire', ['source_type' => 'passive_invoice_line', 'source_id' => $line->id, 'category_id' => $categoryId])
        ->assertUnprocessable()->assertJsonValidationErrors('source_id');
    $this->postJson('/api/v1/eam/assets/acquire', ['source_type' => 'passive_invoice_line', 'source_id' => (string) Str::uuid(), 'category_id' => $categoryId])
        ->assertUnprocessable();
    $this->postJson('/api/v1/eam/assets/acquire', ['source_type' => 'nothing'])->assertUnprocessable()->assertJsonValidationErrors(['source_type', 'source_id', 'category_id']);
});

test('components, custody, assignments, project allocations, books and tags', function (): void {
    $admin = eamApiActor('admin');
    $assetId = eamApiCapitalizedAsset(eamApiCategory());
    $project = Project::factory()->create(['tenant_id' => $this->tenant->id]);

    $componentId = $this->postJson("/api/v1/eam/assets/{$assetId}/components", ['name' => 'Spindle', 'cost_allocation' => '2000'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/eam/assets/{$assetId}/components", ['name' => 'Too much', 'cost_allocation' => '11000'])->assertUnprocessable()->assertJsonValidationErrors('cost_allocation');
    $this->getJson("/api/v1/eam/assets/{$assetId}/components")->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/eam/assets/{$assetId}/components/{$componentId}/retire")->assertOk()->assertJsonPath('data.status', 'replaced');
    $this->postJson("/api/v1/eam/assets/{$assetId}/components/{$componentId}/retire")->assertUnprocessable();

    $this->postJson("/api/v1/eam/assets/{$assetId}/custodians", ['custodian_user_id' => $admin->id, 'department' => 'Ops'])->assertCreated()->assertJsonPath('data.status', 'active');
    $this->postJson("/api/v1/eam/assets/{$assetId}/custodians", ['custodian_user_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('custodian_user_id');
    $this->postJson("/api/v1/eam/assets/{$assetId}/assignments", ['assignee_type' => 'employee', 'assignee_name' => 'Mario Rossi'])->assertCreated()->assertJsonPath('data.assignee_name', 'Mario Rossi');
    $this->postJson("/api/v1/eam/assets/{$assetId}/assignments", ['assignee_type' => 'alien'])->assertUnprocessable()->assertJsonValidationErrors(['assignee_type', 'assignee_name']);
    $this->postJson("/api/v1/eam/assets/{$assetId}/project-allocations", ['project_id' => $project->id, 'hourly_rate' => '25.50', 'utilized_hours' => '10'])
        ->assertCreated()->assertJsonPath('data.attributed_cost', '255.00');
    $this->postJson("/api/v1/eam/assets/{$assetId}/project-allocations", ['project_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('project_id');

    $books = $this->getJson("/api/v1/eam/assets/{$assetId}/books")->assertOk()->json('data');
    expect(collect($books)->pluck('ledger_type')->sort()->values()->all())->toBe(['statutory', 'tax'])
        ->and(collect($books)->firstWhere('ledger_type', 'statutory')['posts_to_gl'])->toBeTrue();

    $tag = $this->getJson("/api/v1/eam/assets/{$assetId}/tag")->assertOk()->json('data');
    expect($tag['barcode_symbology'])->toBe('code128')->and(json_decode($tag['qr_payload'], true)['id'])->toBe($assetId);
    $this->getJson('/api/v1/eam/assets/tags?'.http_build_query(['asset_ids' => [$assetId]]))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/eam/assets/tags')->assertUnprocessable()->assertJsonValidationErrors('asset_ids');
});

test('depreciation endpoints run, list, show, simulate, preview and list schedules', function (): void {
    eamApiActor('admin');
    $assetId = eamApiCapitalizedAsset(eamApiCategory());
    $period = now()->format('Y-m');

    $simulation = $this->postJson('/api/v1/eam/depreciation/simulate', ['period_code' => $period])->assertOk()->json('data');
    expect($simulation['total_depreciation_amount'])->toBe('200.00')->and($simulation['lines'])->toHaveCount(1);

    $preview = $this->getJson("/api/v1/eam/assets/{$assetId}/depreciation-preview?period_type=monthly&periods=120")->assertOk()->json('data');
    expect($preview)->toHaveCount(60)->and($preview[0]['depreciation_amount'])->toBe('200.00')->and(end($preview)['closing_book_value'])->toBe('0.00');

    $this->postJson('/api/v1/eam/depreciation/runs', ['period_code' => '2026/13'])->assertUnprocessable()->assertJsonValidationErrors('period_code');
    $this->postJson('/api/v1/eam/depreciation/runs', ['period_code' => $period, 'ledger_type' => 'cosmic'])->assertUnprocessable()->assertJsonValidationErrors('ledger_type');
    $runId = $this->postJson('/api/v1/eam/depreciation/runs', ['period_code' => $period])->assertCreated()
        ->assertJsonPath('data.status', 'posted')->assertJsonPath('data.total_depreciation_amount', '200.00')->json('data.id');
    $this->postJson('/api/v1/eam/depreciation/runs', ['period_code' => $period])->assertUnprocessable();
    $this->postJson('/api/v1/eam/depreciation/runs', ['period_code' => now()->format('Y'), 'period_type' => 'yearly'])->assertUnprocessable();
    $this->postJson('/api/v1/eam/depreciation/runs', ['period_code' => $period, 'ledger_type' => 'fiscal'])->assertCreated()->assertJsonPath('data.ledger_type', 'tax')->assertJsonPath('data.status', 'calculated');

    $this->getJson('/api/v1/eam/depreciation/runs')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/eam/depreciation/runs?ledger_type=civil')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/eam/depreciation/runs/{$runId}")->assertOk()->assertJsonPath('data.period_code', $period);
    $this->getJson("/api/v1/eam/assets/{$assetId}/depreciation-schedules")->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/eam/assets/{$assetId}/depreciation-schedules?ledger_type=tax")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.journal_entry_id', null);
});

test('asset register reports values per year in JSON and CSV', function (): void {
    eamApiActor('admin');
    $assetId = eamApiCapitalizedAsset(eamApiCategory());
    $this->postJson('/api/v1/eam/depreciation/runs', ['period_code' => now()->format('Y-m')])->assertCreated();

    $register = $this->getJson('/api/v1/eam/reports/asset-register?year='.now()->year)->assertOk();
    $register->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.asset_id', $assetId)
        ->assertJsonPath('data.0.cost_start', '0.00')->assertJsonPath('data.0.additions', '12000.00')
        ->assertJsonPath('data.0.depreciation_year', '200.00')->assertJsonPath('data.0.net_book_value_end', '11800.00');

    $previous = $this->getJson('/api/v1/eam/reports/asset-register?year='.(now()->year - 1))->assertOk();
    expect($previous->json('data'))->toBe([]);

    $csv = $this->get('/api/v1/eam/reports/asset-register?format=csv&year='.now()->year)->assertOk();
    expect($csv->streamedContent())->toContain('asset_number;name')->toContain('11800.00');

    $this->getJson('/api/v1/eam/reports/asset-register')->assertUnprocessable()->assertJsonValidationErrors('year');
});

test('expiring coverage lists warranties and insurances within the horizon', function (): void {
    eamApiActor('admin');
    $categoryId = eamApiCategory();
    eamApiAsset($categoryId, ['name' => 'Soon', 'warranty_expiry_date' => now()->addDays(5)->toDateString()]);
    eamApiAsset($categoryId, ['name' => 'Later', 'insurance_expiry_date' => now()->addDays(90)->toDateString()]);

    $this->getJson('/api/v1/eam/alerts/expiring')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Soon');
    $this->getJson('/api/v1/eam/alerts/expiring?days=120')->assertOk()->assertJsonCount(2, 'data');
});

test('maintenance plans, meter readings and the work order lifecycle with spare parts', function (): void {
    eamApiActor('admin');
    $assetId = eamApiCapitalizedAsset(eamApiCategory());
    $warehouse = Warehouse::create(['tenant_id' => $this->tenant->id, 'name' => 'Main', 'code' => 'MAIN', 'is_primary' => true]);
    $product = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'BELT-1', 'name' => 'Drive belt', 'sale_price' => 20, 'tax_rate' => 22, 'cost_price' => '12.5000', 'track_inventory' => true]);

    $this->postJson("/api/v1/eam/assets/{$assetId}/meter-readings", ['meter_code' => 'hours', 'reading_value' => '100'])->assertCreated();
    $this->postJson('/api/v1/eam/maintenance/plans', ['name' => 'Bad', 'trigger_type' => 'meter'])->assertUnprocessable()->assertJsonValidationErrors(['asset_id', 'meter_code', 'meter_interval_units']);
    $planId = $this->postJson('/api/v1/eam/maintenance/plans', ['name' => '500h service', 'asset_id' => $assetId, 'trigger_type' => 'meter', 'meter_code' => 'hours', 'meter_interval_units' => '500', 'estimated_cost' => '300'])
        ->assertCreated()->assertJsonPath('data.next_due_meter', '600.00')->json('data.id');
    $this->getJson('/api/v1/eam/maintenance/plans/due')->assertOk()->assertJsonCount(0, 'data');

    $this->postJson("/api/v1/eam/assets/{$assetId}/meter-readings", ['meter_code' => 'hours', 'reading_value' => '50'])->assertUnprocessable()->assertJsonValidationErrors('reading_value');
    $this->postJson("/api/v1/eam/assets/{$assetId}/meter-readings", ['meter_code' => 'hours', 'reading_value' => '620'])->assertCreated();
    $this->getJson("/api/v1/eam/assets/{$assetId}/meter-readings?meter_code=hours")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.reading_value', '620.00');
    $this->getJson('/api/v1/eam/maintenance/plans/due')->assertOk()->assertJsonCount(1, 'data');

    $this->getJson('/api/v1/eam/maintenance/plans')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/eam/maintenance/plans/{$planId}")->assertOk()->assertJsonPath('data.trigger_type', 'meter');
    $this->putJson("/api/v1/eam/maintenance/plans/{$planId}", ['lead_time_days' => 7])->assertOk()->assertJsonPath('data.lead_time_days', 7);

    $workOrderId = $this->postJson("/api/v1/eam/maintenance/plans/{$planId}/work-orders")->assertCreated()->assertJsonPath('data.plan_id', $planId)->json('data.id');
    $this->postJson("/api/v1/eam/maintenance/plans/{$planId}/work-orders")->assertUnprocessable();

    $this->postJson("/api/v1/eam/maintenance/work-orders/{$workOrderId}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
    expect(FixedAsset::find($assetId)->status)->toBe('under_maintenance');
    $this->postJson("/api/v1/eam/maintenance/work-orders/{$workOrderId}/start")->assertUnprocessable();

    $this->postJson("/api/v1/eam/maintenance/work-orders/{$workOrderId}/parts", ['product_id' => $product->id, 'quantity' => '4', 'warehouse_id' => $warehouse->id])
        ->assertCreated()->assertJsonPath('data.total_cost', '50.00');
    $this->postJson("/api/v1/eam/maintenance/work-orders/{$workOrderId}/parts", ['product_id' => (string) Str::uuid(), 'quantity' => '0'])->assertUnprocessable()->assertJsonValidationErrors(['product_id', 'quantity']);
    expect(StockMovement::where('product_id', $product->id)->where('type', 'out')->value('quantity'))->not->toBeNull();
    $this->postJson("/api/v1/eam/maintenance/work-orders/{$workOrderId}/cancel")->assertUnprocessable();

    $this->postJson("/api/v1/eam/maintenance/work-orders/{$workOrderId}/complete", ['labor_cost' => '100', 'external_service_cost' => '30', 'downtime_hours' => '3.5', 'meter_reading' => '640'])
        ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.total_cost', '180.00')->assertJsonPath('data.parts_cost', '50.00');
    expect(FixedAsset::find($assetId)->status)->toBe('capitalized')
        ->and(MaintenancePlan::find($planId)->next_due_meter)->toBe('1140.00');
    $this->postJson("/api/v1/eam/maintenance/work-orders/{$workOrderId}/complete")->assertUnprocessable();

    $show = $this->getJson("/api/v1/eam/maintenance/work-orders/{$workOrderId}")->assertOk();
    expect($show->json('parts'))->toHaveCount(1);
    $this->getJson("/api/v1/eam/assets/{$assetId}/maintenance-summary")->assertOk()->assertJsonPath('data.total_cost', '180.00')->assertJsonPath('data.downtime_hours', '3.50');
});

test('work orders can be created, filtered and cancelled, and improvements capitalized', function (): void {
    eamApiActor('admin');
    $assetId = eamApiCapitalizedAsset(eamApiCategory());

    $this->postJson('/api/v1/eam/maintenance/work-orders', ['asset_id' => (string) Str::uuid(), 'priority' => 'urgentissimo'])->assertUnprocessable()->assertJsonValidationErrors(['asset_id', 'priority']);
    $cancelled = $this->postJson('/api/v1/eam/maintenance/work-orders', ['asset_id' => $assetId, 'maintenance_type' => 'corrective', 'priority' => 'high'])->assertCreated()->json('data.id');
    expect(FixedAsset::find($assetId)->status)->toBe('under_maintenance');
    $this->postJson("/api/v1/eam/maintenance/work-orders/{$cancelled}/cancel", ['reason' => 'Duplicate'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(FixedAsset::find($assetId)->status)->toBe('capitalized');

    $upgrade = $this->postJson('/api/v1/eam/maintenance/work-orders', ['asset_id' => $assetId, 'maintenance_type' => 'upgrade'])->assertCreated()->json('data.id');
    $this->getJson('/api/v1/eam/maintenance/work-orders?status=scheduled')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/eam/maintenance/work-orders/{$upgrade}/complete", ['external_service_cost' => '3000', 'capitalize_as_upgrade' => true])->assertOk();

    expect(FixedAsset::find($assetId))->capitalized_cost->toBe('15000.00')->carrying_amount->toBe('15000.00')
        ->and(MaintenanceWorkOrder::find($upgrade)->journal_entry_id)->not->toBeNull();
});

test('a read-only member reads the module but cannot write anything', function (): void {
    eamApiActor('admin');
    $categoryId = eamApiCategory();
    $assetId = eamApiCapitalizedAsset($categoryId);
    $planId = $this->postJson('/api/v1/eam/maintenance/plans', ['name' => 'Yearly', 'asset_id' => $assetId])->json('data.id');
    $workOrderId = $this->postJson('/api/v1/eam/maintenance/work-orders', ['asset_id' => $assetId])->json('data.id');
    $locationId = $this->postJson('/api/v1/eam/locations', ['code' => 'L1', 'name' => 'L1'])->json('data.id');

    eamApiActor('member');

    foreach (['/api/v1/eam/assets', "/api/v1/eam/assets/{$assetId}", "/api/v1/eam/assets/{$assetId}/books", "/api/v1/eam/assets/{$assetId}/history", '/api/v1/eam/categories', '/api/v1/eam/maintenance/plans', '/api/v1/eam/depreciation/runs', '/api/v1/eam/alerts/expiring'] as $readable) {
        $this->getJson($readable)->assertOk();
    }

    $writes = [
        ['post', '/api/v1/eam/categories'], ['put', "/api/v1/eam/categories/{$categoryId}"], ['post', '/api/v1/eam/classes'], ['post', '/api/v1/eam/locations'],
        ['put', "/api/v1/eam/locations/{$locationId}"], ['post', '/api/v1/eam/assets'], ['post', '/api/v1/eam/assets/acquire'], ['patch', "/api/v1/eam/assets/{$assetId}"],
        ['put', "/api/v1/eam/assets/{$assetId}"], ['delete', "/api/v1/eam/assets/{$assetId}"], ['post', "/api/v1/eam/assets/{$assetId}/capitalize"], ['post', "/api/v1/eam/assets/{$assetId}/commission"],
        ['post', "/api/v1/eam/assets/{$assetId}/transfer"], ['post', "/api/v1/eam/assets/{$assetId}/valuate"], ['post', "/api/v1/eam/assets/{$assetId}/reverse-impairment"],
        ['post', "/api/v1/eam/assets/{$assetId}/dispose"], ['post', "/api/v1/eam/assets/{$assetId}/components"], ['post', "/api/v1/eam/assets/{$assetId}/custodians"],
        ['post', "/api/v1/eam/assets/{$assetId}/assignments"], ['post', "/api/v1/eam/assets/{$assetId}/project-allocations"], ['post', "/api/v1/eam/assets/{$assetId}/meter-readings"],
        ['post', '/api/v1/eam/depreciation/runs'], ['post', '/api/v1/eam/depreciation/simulate'], ['post', '/api/v1/eam/maintenance/plans'], ['put', "/api/v1/eam/maintenance/plans/{$planId}"],
        ['post', "/api/v1/eam/maintenance/plans/{$planId}/work-orders"], ['post', '/api/v1/eam/maintenance/work-orders'], ['post', "/api/v1/eam/maintenance/work-orders/{$workOrderId}/start"],
        ['post', "/api/v1/eam/maintenance/work-orders/{$workOrderId}/parts"], ['post', "/api/v1/eam/maintenance/work-orders/{$workOrderId}/complete"], ['post', "/api/v1/eam/maintenance/work-orders/{$workOrderId}/cancel"],
    ];

    foreach ($writes as [$method, $uri]) {
        $this->json($method, $uri, [])->assertForbidden();
    }

    expect(FixedAsset::find($assetId)->status)->toBe('capitalized');
});

test('records of another company are not found and cannot be referenced', function (): void {
    eamApiActor('admin', $this->otherTenant);
    $foreignCategory = eamApiCategory();
    $foreignAsset = eamApiAsset($foreignCategory);
    $foreignLocation = $this->postJson('/api/v1/eam/locations', ['code' => 'F', 'name' => 'Foreign'])->json('data.id');
    $foreignPlan = $this->postJson('/api/v1/eam/maintenance/plans', ['name' => 'Foreign', 'asset_id' => $foreignAsset])->json('data.id');
    $foreignWorkOrder = $this->postJson('/api/v1/eam/maintenance/work-orders', ['asset_id' => $foreignAsset])->json('data.id');

    eamApiActor('admin');
    app(TenantContext::class)->setTenant($this->tenant);
    $categoryId = eamApiCategory();

    foreach ([
        "/api/v1/eam/assets/{$foreignAsset}", "/api/v1/eam/assets/{$foreignAsset}/books", "/api/v1/eam/assets/{$foreignAsset}/tag",
        "/api/v1/eam/assets/{$foreignAsset}/depreciation-schedules", "/api/v1/eam/categories/{$foreignCategory}",
        "/api/v1/eam/maintenance/plans/{$foreignPlan}", "/api/v1/eam/maintenance/work-orders/{$foreignWorkOrder}",
    ] as $uri) {
        $this->getJson($uri)->assertNotFound();
    }

    $this->postJson("/api/v1/eam/assets/{$foreignAsset}/dispose", ['disposal_type' => 'scrap'])->assertNotFound();
    $this->postJson("/api/v1/eam/maintenance/work-orders/{$foreignWorkOrder}/complete")->assertNotFound();
    $this->putJson("/api/v1/eam/locations/{$foreignLocation}", ['name' => 'Mine'])->assertNotFound();
    $this->postJson('/api/v1/eam/assets', ['name' => 'X', 'category_id' => $foreignCategory, 'acquisition_cost' => '1'])->assertUnprocessable()->assertJsonValidationErrors('category_id');
    $this->postJson('/api/v1/eam/maintenance/work-orders', ['asset_id' => $foreignAsset])->assertUnprocessable()->assertJsonValidationErrors('asset_id');
    $this->getJson('/api/v1/eam/assets')->assertOk()->assertJsonCount(0, 'data');
    expect($categoryId)->not->toBe($foreignCategory);
});

test('unauthenticated requests are refused', function (): void {
    $this->getJson('/api/v1/eam/assets')->assertUnauthorized();
    $this->postJson('/api/v1/eam/depreciation/runs', [])->assertUnauthorized();
});
