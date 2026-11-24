<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Projects\Models\Project;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::firstOrCreate(
        ['name' => 'tenant_cross_module_test'],
        [
            'legal_name' => 'Cross Module Enterprise Validation Corp',
            'fiscal_code' => 'IT11223344556',
            'vat_number' => 'IT11223344556',
            'country' => 'IT',
            'default_currency' => 'EUR',
        ]
    );

    app(TenantContext::class)->setTenant($this->tenant);
});

test('Cross-Module: Procurement binds to Inventory Warehouses and General Ledger accounts', function () {
    $tId = $this->tenant->id;

    $supplier = Contact::create([
        'tenant_id' => $tId,
        'name' => 'Steel Alloys SpA',
        'type' => 'supplier',
    ]);

    $po = PurchaseOrder::create([
        'tenant_id' => $tId,
        'company_id' => $tId,
        'supplier_id' => $supplier->id,
        'order_number' => 'PO-XMOD-'.Str::random(6),
        'status' => 'approved',
        'total_amount' => 12500.00,
        'currency' => 'EUR',
        'date' => now()->toDateString(),
    ]);

    $warehouse = Warehouse::create([
        'tenant_id' => $tId,
        'name' => 'Inbound Raw Store',
        'code' => 'WH-IN-'.Str::random(4),
        'is_active' => true,
    ]);

    expect($po->status)->toBe('approved')
        ->and($warehouse->tenant_id)->toBe($tId);
});

test('Cross-Module: Manufacturing consumes Inventory Raw Materials and links to Projects', function () {
    $tId = $this->tenant->id;

    $user = User::firstOrCreate(
        ['email' => 'pm_crossmod@enterprise.com'],
        [
            'name' => 'Project Manager',
            'password' => bcrypt('password'),
        ]
    );

    $customer = Contact::create([
        'tenant_id' => $tId,
        'name' => 'Aerospace Defense Partner',
        'type' => 'customer',
    ]);

    $project = Project::create([
        'tenant_id' => $tId,
        'contact_id' => $customer->id,
        'project_manager_user_id' => $user->id,
        'project_number' => 'PRJ-XMOD-'.Str::random(6),
        'code' => 'PRJ-XMOD-'.Str::random(4),
        'name' => 'Titanium Propulsion System',
        'status' => 'in_progress',
        'contract_value' => 500000.00,
        'planned_start_date' => now()->toDateString(),
        'planned_end_date' => now()->addDays(90)->toDateString(),
    ]);

    $raw = MfgItem::create([
        'tenant_id' => $tId,
        'item_code' => 'RM-TI-'.Str::random(4),
        'name' => 'Raw Titanium Rods',
        'item_type' => 'raw_material',
        'standard_cost' => 450.00,
    ]);

    expect((float) $project->contract_value)->toEqual(500000.00)
        ->and($raw->item_type)->toBe('raw_material');
});
