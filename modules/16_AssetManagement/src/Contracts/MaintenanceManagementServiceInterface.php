<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Contracts;

use Illuminate\Support\Collection;
use Modules\AssetManagement\DTOs\CompleteMaintenanceWorkOrderDTO;
use Modules\AssetManagement\DTOs\CreateMaintenanceWorkOrderDTO;
use Modules\AssetManagement\Models\AssetMeterReading;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Models\MaintenancePlan;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;
use Modules\AssetManagement\Models\WorkOrderPart;

/**
 * Maintenance of the assets (EAM): preventive plans triggered by time or meter, work orders with their lifecycle,
 * spare parts issued from the inventory, downtime and cost tracking, meter readings.
 */
interface MaintenanceManagementServiceInterface
{
    /**
     * Creates a preventive plan for an asset or an asset class.
     *
     * @param  array<string, mixed>  $data
     */
    public function createMaintenancePlan(array $data, ?string $actorUserId = null): MaintenancePlan;

    /**
     * Updates a plan (frequency, meter interval, activation).
     *
     * @param  array<string, mixed>  $data
     */
    public function updateMaintenancePlan(MaintenancePlan $plan, array $data, ?string $actorUserId = null): MaintenancePlan;

    /**
     * Active plans due by a date (time) or by the latest meter reading (meter).
     *
     * @return Collection<int, MaintenancePlan>
     */
    public function duePlans(string $tenantId, ?string $asOf = null): Collection;

    /**
     * Opens the preventive work order of a plan; one open work order per plan at a time.
     */
    public function generateWorkOrderFromPlan(MaintenancePlan $plan, ?string $scheduledDate = null, ?string $actorUserId = null): MaintenanceWorkOrder;

    /**
     * Creates a work order.
     */
    public function createWorkOrder(CreateMaintenanceWorkOrderDTO $dto, ?string $actorUserId = null): MaintenanceWorkOrder;

    /**
     * Starts the execution of a work order; with stopsAsset the downtime of the asset starts too.
     */
    public function startWorkOrder(MaintenanceWorkOrder $workOrder, bool $stopsAsset = true, ?string $actorUserId = null): MaintenanceWorkOrder;

    /**
     * Issues a spare part from the inventory to the work order.
     */
    public function consumePart(MaintenanceWorkOrder $workOrder, string $productId, string|int|float $quantity, ?string $warehouseId = null, ?string $actorUserId = null): WorkOrderPart;

    /**
     * Completes a work order with its costs and downtime.
     */
    public function completeWorkOrder(CompleteMaintenanceWorkOrderDTO $dto, ?string $actorUserId = null): MaintenanceWorkOrder;

    /**
     * Cancels a work order that is not completed.
     */
    public function cancelWorkOrder(MaintenanceWorkOrder $workOrder, ?string $reason = null, ?string $actorUserId = null): MaintenanceWorkOrder;

    /**
     * Records a cumulative meter reading of an asset (never lower than the previous one of the same meter).
     */
    public function recordMeterReading(FixedAsset $asset, string $meterCode, string|int|float $value, ?string $readAt = null, ?string $notes = null, ?string $actorUserId = null): AssetMeterReading;

    /**
     * Maintenance totals of an asset: work orders, costs, downtime, mean time between corrective failures.
     *
     * @return array<string, mixed>
     */
    public function maintenanceSummary(FixedAsset $asset): array;
}
