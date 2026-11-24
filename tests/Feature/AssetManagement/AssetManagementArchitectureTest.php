<?php

declare(strict_types=1);

namespace Tests\Feature\AssetManagement;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\AssetManagement\Contracts\AssetAccountingServiceInterface;
use Modules\AssetManagement\Contracts\AssetLifecycleServiceInterface;
use Modules\AssetManagement\Contracts\DepreciationEngineServiceInterface;
use Modules\AssetManagement\Contracts\MaintenanceManagementServiceInterface;
use Modules\AssetManagement\Contracts\ProjectAssetIntegrationServiceInterface;
use Modules\AssetManagement\Events\AssetCapitalizedEvent;
use Modules\AssetManagement\Events\AssetDepreciationCalculatedEvent;
use Modules\AssetManagement\Events\AssetDepreciationPostedEvent;
use Modules\AssetManagement\Events\AssetDepreciationReadyEvent;
use Modules\AssetManagement\Events\AssetDisposalCashForecastEvent;
use Modules\AssetManagement\Events\AssetDisposalReadyEvent;
use Modules\AssetManagement\Events\AssetDisposedEvent;
use Modules\AssetManagement\Events\AssetImpairedEvent;
use Modules\AssetManagement\Events\AssetImpairmentReadyEvent;
use Modules\AssetManagement\Events\AssetMaintenanceCreatedEvent;
use Modules\AssetManagement\Events\AssetPurchaseForecastEvent;
use Modules\AssetManagement\Events\AssetRegisteredEvent;
use Modules\AssetManagement\Events\AssetReplacementForecastEvent;
use Modules\AssetManagement\Events\AssetRevaluationReadyEvent;
use Modules\AssetManagement\Events\AssetRevaluedEvent;
use Modules\AssetManagement\Events\AssetTransferredEvent;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\AssetClass;
use Modules\AssetManagement\Models\AssetComponent;
use Modules\AssetManagement\Models\AssetCustodian;
use Modules\AssetManagement\Models\AssetDisposal;
use Modules\AssetManagement\Models\AssetDocument;
use Modules\AssetManagement\Models\AssetLocation;
use Modules\AssetManagement\Models\AssetRelationship;
use Modules\AssetManagement\Models\AssetTransfer;
use Modules\AssetManagement\Models\AssetValuation;
use Modules\AssetManagement\Models\DepreciationRun;
use Modules\AssetManagement\Models\DepreciationSchedule;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Models\MaintenancePlan;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;
use Modules\AssetManagement\Models\ProjectAssetAllocation;
use Modules\AssetManagement\OpenApi\AssetManagementOpenApiSchemas;
use Modules\AssetManagement\Services\AssetAccountingService;
use Modules\AssetManagement\Services\AssetLifecycleService;
use Modules\AssetManagement\Services\DepreciationEngineService;
use Modules\AssetManagement\Services\MaintenanceManagementService;
use Modules\AssetManagement\Services\ProjectAssetIntegrationService;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use ReflectionClass;

test('all 16 enterprise asset management models inherit from BaseEnterpriseModel', function (): void {
    $models = [
        AssetCategory::class,
        AssetClass::class,
        AssetLocation::class,
        FixedAsset::class,
        AssetComponent::class,
        AssetCustodian::class,
        AssetDocument::class,
        AssetRelationship::class,
        AssetTransfer::class,
        DepreciationRun::class,
        DepreciationSchedule::class,
        AssetValuation::class,
        AssetDisposal::class,
        MaintenancePlan::class,
        MaintenanceWorkOrder::class,
        ProjectAssetAllocation::class,
    ];

    foreach ($models as $modelClass) {
        $reflection = new ReflectionClass($modelClass);
        expect($reflection->isSubclassOf(BaseEnterpriseModel::class))->toBeTrue();
    }
});

test('all 16 enterprise asset management events implement DomainEventInterface', function (): void {
    $events = [
        AssetRegisteredEvent::class,
        AssetCapitalizedEvent::class,
        AssetTransferredEvent::class,
        AssetRevaluedEvent::class,
        AssetImpairedEvent::class,
        AssetDisposedEvent::class,
        AssetMaintenanceCreatedEvent::class,
        AssetDepreciationCalculatedEvent::class,
        AssetDepreciationPostedEvent::class,
        AssetDepreciationReadyEvent::class,
        AssetImpairmentReadyEvent::class,
        AssetDisposalReadyEvent::class,
        AssetRevaluationReadyEvent::class,
        AssetPurchaseForecastEvent::class,
        AssetReplacementForecastEvent::class,
        AssetDisposalCashForecastEvent::class,
    ];

    foreach ($events as $eventClass) {
        $reflection = new ReflectionClass($eventClass);
        expect($reflection->implementsInterface(DomainEventInterface::class))->toBeTrue();
    }
});

test('all enterprise asset management service contracts are bound to concrete services', function (): void {
    expect(app(AssetLifecycleServiceInterface::class))->toBeInstanceOf(AssetLifecycleService::class)
        ->and(app(DepreciationEngineServiceInterface::class))->toBeInstanceOf(DepreciationEngineService::class)
        ->and(app(AssetAccountingServiceInterface::class))->toBeInstanceOf(AssetAccountingService::class)
        ->and(app(MaintenanceManagementServiceInterface::class))->toBeInstanceOf(MaintenanceManagementService::class)
        ->and(app(ProjectAssetIntegrationServiceInterface::class))->toBeInstanceOf(ProjectAssetIntegrationService::class);
});

test('workflow-governed asset models implement LockableByWorkflowInterface and OpenAPI schemas exist', function (): void {
    $lockableModels = [
        FixedAsset::class,
        AssetTransfer::class,
        AssetValuation::class,
        AssetDisposal::class,
        MaintenanceWorkOrder::class,
    ];

    foreach ($lockableModels as $modelClass) {
        $reflection = new ReflectionClass($modelClass);
        expect($reflection->implementsInterface(LockableByWorkflowInterface::class))->toBeTrue();
    }

    $schemas = AssetManagementOpenApiSchemas::schemas();
    expect($schemas)->toHaveKeys(['EamFixedAsset', 'EamDepreciationRun', 'EamMaintenanceWorkOrder']);
});
