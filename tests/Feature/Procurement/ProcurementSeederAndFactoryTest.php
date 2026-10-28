<?php

declare(strict_types=1);

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\ProcurementDemoDataSeeder;
use Database\Seeders\ProcurementLookupSeeder;
use Database\Seeders\ProcurementReferenceSeeder;
use Database\Seeders\ProcurementTestingSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierContract;
use Modules\Procurement\Models\ThreeWayMatchLog;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    Gate::before(fn () => true);

    $this->tenant = Tenant::first() ?? Tenant::create([
        'name' => 'Seeder Test Tenant',
        'legal_name' => 'Seeder Test Corp',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::first() ?? User::create([
        'name' => 'Admin User',
        'email' => 'admin@procurement.test',
        'password' => bcrypt('password123'),
    ]);

    Sanctum::actingAs($this->user);
});

test('procurement seeders are strictly idempotent and can run multiple times cleanly', function (): void {
    // Run seeders first time
    $this->seed(ProcurementLookupSeeder::class);
    $this->seed(ProcurementReferenceSeeder::class);
    $this->seed(ProcurementTestingSeeder::class);
    $this->seed(ProcurementDemoDataSeeder::class);

    $supplierCountFirst = Supplier::count();
    $poCountFirst = PurchaseOrder::count();

    // Run seeders second time
    $this->seed(ProcurementLookupSeeder::class);
    $this->seed(ProcurementReferenceSeeder::class);
    $this->seed(ProcurementTestingSeeder::class);
    $this->seed(ProcurementDemoDataSeeder::class);

    expect(Supplier::count())->toBe($supplierCountFirst);
    expect(PurchaseOrder::count())->toBe($poCountFirst);
});

test('procurement lookup items contain complete multilingual translations in 4 languages', function (): void {
    $this->seed(ProcurementLookupSeeder::class);

    $incotermsCat = LookupCategory::where('code', 'procurement_incoterms')->first();
    expect($incotermsCat)->not->toBeNull();

    $exwItem = LookupItem::where('category_id', $incotermsCat->id)->where('code', 'EXW')->first();
    expect($exwItem)->not->toBeNull();

    $translations = $exwItem->translations()->pluck('label', 'locale')->toArray();

    expect($translations)->toHaveKey('en');
    expect($translations)->toHaveKey('it');
    expect($translations)->toHaveKey('de');
    expect($translations)->toHaveKey('fr');

    expect($translations['en'])->toBe('Ex Works');
    expect($translations['it'])->toBe('Franco Fabbrica');
    expect($translations['de'])->toBe('Ab Werk');
    expect($translations['fr'])->toBe('À l\'usine');
});

test('procurement factories generate valid entities with domain states', function (): void {
    $supplier = Supplier::factory()->approved()->create();
    expect($supplier->is_approved_vendor)->toBeTrue();
    expect($supplier->status)->toBe('active');

    $suspendedSupplier = Supplier::factory()->suspended()->create();
    expect($suspendedSupplier->status)->toBe('suspended');

    $po = PurchaseOrder::factory()->approved()->create();
    expect($po->status)->toBe('approved');
    expect($po->is_budget_encumbered)->toBeTrue();

    $partiallyReceivedPo = PurchaseOrder::factory()->partiallyReceived()->create();
    expect($partiallyReceivedPo->status)->toBe('partially_received');

    $req = PurchaseRequisition::factory()->approved()->create();
    expect($req->status)->toBe('approved');

    $convertedReq = PurchaseRequisition::factory()->convertedToPo()->create();
    expect($convertedReq->status)->toBe('converted_to_po');

    $contract = SupplierContract::factory()->framework()->create();
    expect($contract->contract_type)->toBe('framework');
    expect((float) $contract->total_committed_amount)->toBe(500000.00);

    $receipt = PurchaseReceipt::factory()->quarantined()->create();
    expect($receipt->inspection_status)->toBe('quarantined');

    $matchLog = ThreeWayMatchLog::factory()->escalated()->create();
    expect($matchLog->match_status)->toBe('escalated_to_workflow');
});
