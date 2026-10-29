<?php

declare(strict_types=1);

namespace Tests\Feature\AssetManagement;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Modules\AssetManagement\Contracts\MaintenanceManagementServiceInterface;
use Modules\AssetManagement\DTOs\CompleteMaintenanceWorkOrderDTO;
use Modules\AssetManagement\DTOs\CreateMaintenanceWorkOrderDTO;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\FixedAsset;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'EAM Maintenance Tenant', 'legal_name' => 'EAM Maintenance Tenant SpA']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::firstOrCreate(
        ['email' => 'eam.tech@example.com'],
        ['id' => (string) Str::uuid7(), 'name' => 'Lead Maintenance Engineer', 'password' => bcrypt('secret')]
    );

    $this->category = AssetCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->maintenanceService = app(MaintenanceManagementServiceInterface::class);
});

test('creates preventive maintenance plan, schedules work order, and tracks downtime and costs', function (): void {
    $asset = FixedAsset::factory()->active()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
    ]);

    $plan = $this->maintenanceService->createMaintenancePlan([
        'tenant_id' => (string) $this->tenant->id,
        'asset_id' => (string) $asset->id,
        'name' => '90-Day Hydraulic Calibration',
        'maintenance_type' => 'preventive',
        'frequency_days' => 90,
        'estimated_duration_hours' => 3.5,
        'estimated_cost' => 800.00,
    ], (string) $this->user->id);

    $wo = $this->maintenanceService->createWorkOrder(new CreateMaintenanceWorkOrderDTO(
        tenantId: (string) $this->tenant->id,
        assetId: (string) $asset->id,
        maintenanceType: 'corrective',
        priority: 'high',
        planId: (string) $plan->id,
        assignedTechnicianId: (string) $this->user->id,
        problemDescription: 'Hydraulic seal pressure drop',
        estimatedCost: 850.00
    ), (string) $this->user->id);

    expect($asset->fresh()->status)->toBe('under_maintenance');

    $completed = $this->maintenanceService->completeWorkOrder(new CompleteMaintenanceWorkOrderDTO(
        workOrderId: (string) $wo->id,
        downtimeHours: 4.5,
        laborCost: 400.00,
        partsCost: 350.00,
        externalServiceCost: 150.00,
        capitalizeAsUpgrade: false,
        conditionAfterMaintenance: 'excellent',
        resolutionNotes: 'Replaced high-pressure hydraulic seals and recalibrated'
    ), (string) $this->user->id);

    expect($completed->status)->toBe('completed')
        ->and((float) $completed->downtime_hours)->toBe(4.5)
        ->and((float) $completed->total_cost)->toBe(900.00)
        ->and($asset->fresh()->status)->toBe('in_service')
        ->and($asset->fresh()->condition)->toBe('excellent')
        ->and($plan->fresh()->last_executed_date)->not->toBeNull();
});

test('capitalizes major upgrade work order into asset carrying amount and depreciable base', function (): void {
    $asset = FixedAsset::factory()->capitalized()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'acquisition_cost' => 50000.00,
        'capitalized_cost' => 50000.00,
        'salvage_value' => 5000.00,
        'depreciable_base' => 45000.00,
        'carrying_amount' => 40000.00,
    ]);

    $wo = $this->maintenanceService->createWorkOrder(new CreateMaintenanceWorkOrderDTO(
        tenantId: (string) $this->tenant->id,
        assetId: (string) $asset->id,
        maintenanceType: 'upgrade',
        priority: 'medium',
        problemDescription: 'Robotic auto-loader retrofit upgrade',
        estimatedCost: 12000.00
    ), (string) $this->user->id);

    $this->maintenanceService->completeWorkOrder(new CompleteMaintenanceWorkOrderDTO(
        workOrderId: (string) $wo->id,
        downtimeHours: 12.0,
        laborCost: 3000.00,
        partsCost: 9000.00,
        externalServiceCost: 0.00,
        capitalizeAsUpgrade: true,
        conditionAfterMaintenance: 'excellent'
    ), (string) $this->user->id);

    $refreshed = $asset->fresh();
    expect((float) $refreshed->capitalized_cost)->toBe(62000.00)
        ->and((float) $refreshed->carrying_amount)->toBe(52000.00)
        ->and((float) $refreshed->depreciable_base)->toBe(47000.00);
});
