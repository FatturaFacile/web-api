<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\AssetManagement\Contracts\MaintenanceManagementServiceInterface;
use Modules\AssetManagement\DTOs\CompleteMaintenanceWorkOrderDTO;
use Modules\AssetManagement\DTOs\CreateMaintenanceWorkOrderDTO;
use Modules\AssetManagement\Events\AssetMaintenanceCreatedEvent;
use Modules\AssetManagement\Events\MaintenanceCompletedEvent;
use Modules\AssetManagement\Events\MaintenanceWorkOrderCreatedEvent;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Models\MaintenanceActivity;
use Modules\AssetManagement\Models\MaintenancePlan;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\WorkflowDefinition;

final readonly class MaintenanceManagementService implements MaintenanceManagementServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private ?WorkflowEngineInterface $workflowEngine = null
    ) {}

    public function createMaintenancePlan(array $data, ?string $actorUserId = null): MaintenancePlan
    {
        return DB::transaction(function () use ($data) {
            $freqDays = (int) ($data['frequency_days'] ?? 90);
            $nextDue = $data['next_due_date'] ?? date('Y-m-d', strtotime("+{$freqDays} days"));

            $plan = MaintenancePlan::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $data['tenant_id'],
                'asset_id' => $data['asset_id'] ?? null,
                'asset_class_id' => $data['asset_class_id'] ?? null,
                'plan_code' => $data['plan_code'] ?? 'MPLAN-'.strtoupper(Str::random(6)),
                'name' => $data['name'],
                'maintenance_type' => $data['maintenance_type'] ?? 'preventive',
                'frequency_days' => $freqDays,
                'meter_interval_units' => isset($data['meter_interval_units']) ? (float) $data['meter_interval_units'] : null,
                'estimated_duration_hours' => (float) ($data['estimated_duration_hours'] ?? 2.0),
                'estimated_cost' => (float) ($data['estimated_cost'] ?? 0.0),
                'next_due_date' => $nextDue,
                'is_active' => true,
                'checklist' => $data['checklist'] ?? [],
                'translations' => $data['translations'] ?? [],
            ]);

            $this->auditLedger->record(
                action: 'MAINTENANCE_PLAN_CREATED',
                model: $plan,
                payloadBefore: null,
                payloadAfter: $plan->toArray(),
                moduleCode: 'MODULE_16_EAM'
            );

            return $plan;
        });
    }

    public function createWorkOrder(CreateMaintenanceWorkOrderDTO $dto, ?string $actorUserId = null): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $asset = FixedAsset::where('id', $dto->assetId)->lockForUpdate()->firstOrFail();

            if (in_array($asset->status, ['disposed', 'sold', 'retired', 'closed'], true)) {
                throw new InvalidArgumentException("Cannot create maintenance work order for disposed asset [{$asset->asset_number}].");
            }

            $scheduledDate = $dto->scheduledDate ?: date('Y-m-d');
            $woNumber = 'MWO-'.date('Ymd').'-'.strtoupper(Str::random(5));

            $workOrder = MaintenanceWorkOrder::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $dto->tenantId,
                'asset_id' => $asset->id,
                'plan_id' => $dto->planId,
                'work_order_number' => $woNumber,
                'maintenance_type' => $dto->maintenanceType,
                'priority' => $dto->priority,
                'status' => 'scheduled',
                'scheduled_date' => $scheduledDate,
                'total_cost' => $dto->estimatedCost,
                'assigned_technician_id' => $dto->assignedTechnicianId,
                'problem_description' => $dto->problemDescription,
            ]);

            // Large Maintenance Cost Approval via Workflow Engine V2
            if ($dto->estimatedCost >= 10000.0 && $this->workflowEngine !== null) {
                $hasDef = WorkflowDefinition::where('tenant_id', $dto->tenantId)
                    ->where('code', 'LARGE_MAINTENANCE_APPROVAL')
                    ->where('is_active', true)
                    ->exists();

                if ($hasDef) {
                    $initiatorId = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : (string) Str::uuid7();
                    $instance = $this->workflowEngine->startWorkflow(new StartWorkflowDTO(
                        workflowCode: 'LARGE_MAINTENANCE_APPROVAL',
                        approvable: $workOrder,
                        contextSnapshot: [
                            'work_order_number' => $workOrder->work_order_number,
                            'estimated_cost' => $dto->estimatedCost,
                        ],
                        tenantId: $dto->tenantId,
                        companyId: $dto->tenantId,
                        initiatorUserId: $initiatorId
                    ));
                    $workOrder->lockByWorkflow($instance);
                }
            }

            if (in_array($dto->maintenanceType, ['corrective', 'emergency'], true) || $dto->priority === 'critical') {
                $asset->update(['status' => 'under_maintenance']);
            }

            $this->auditLedger->record(
                action: 'MAINTENANCE_WORK_ORDER_CREATED',
                model: $workOrder,
                payloadBefore: null,
                payloadAfter: $workOrder->fresh()->toArray(),
                moduleCode: 'MODULE_16_EAM'
            );

            $companyId = (string) ($asset->company_id ?? $dto->tenantId);

            $event = new AssetMaintenanceCreatedEvent(
                workOrderId: (string) $workOrder->id,
                workOrderNumber: $woNumber,
                assetId: (string) $asset->id,
                maintenanceType: $dto->maintenanceType,
                priority: $dto->priority,
                totalCost: $dto->estimatedCost,
                scheduledDate: $scheduledDate,
                tenantId: $dto->tenantId,
                companyId: $companyId
            );
            $this->dispatcher->publish($event);
            event($event);

            $woCreatedEvent = new MaintenanceWorkOrderCreatedEvent(
                workOrderId: (string) $workOrder->id,
                workOrderNumber: $woNumber,
                assetId: (string) $asset->id,
                workOrderType: $dto->maintenanceType,
                priority: $dto->priority,
                estimatedCost: $dto->estimatedCost,
                scheduledDate: $scheduledDate,
                tenantId: $dto->tenantId,
                companyId: $companyId
            );
            $this->dispatcher->publish($woCreatedEvent);
            event($woCreatedEvent);

            return $workOrder->fresh();
        });
    }

    public function completeWorkOrder(CompleteMaintenanceWorkOrderDTO $dto, ?string $actorUserId = null): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $workOrder = MaintenanceWorkOrder::where('id', $dto->workOrderId)->lockForUpdate()->firstOrFail();

            if ($workOrder->status === 'completed') {
                throw new InvalidArgumentException("Maintenance work order [{$workOrder->work_order_number}] is already completed.");
            }

            if ($workOrder->is_locked) {
                throw new InvalidArgumentException("Maintenance work order [{$workOrder->work_order_number}] is locked awaiting workflow approval.");
            }

            $beforeWo = $workOrder->toArray();
            $asset = FixedAsset::where('id', $workOrder->asset_id)->lockForUpdate()->firstOrFail();
            $beforeAsset = $asset->toArray();

            $totalCost = round($dto->laborCost + $dto->partsCost + $dto->externalServiceCost, 2);
            $completedAt = now();

            $workOrder->update([
                'status' => 'completed',
                'completed_at' => $completedAt,
                'downtime_hours' => $dto->downtimeHours,
                'labor_cost' => $dto->laborCost,
                'parts_cost' => $dto->partsCost,
                'external_service_cost' => $dto->externalServiceCost,
                'total_cost' => $totalCost,
                'capitalize_as_upgrade' => $dto->capitalizeAsUpgrade,
                'condition_after_maintenance' => $dto->conditionAfterMaintenance,
                'resolution_notes' => $dto->resolutionNotes,
                'parts_consumed' => $dto->partsConsumed,
            ]);

            $actorUuid = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : $workOrder->assigned_technician_id;

            MaintenanceActivity::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $workOrder->tenant_id,
                'company_id' => $asset->company_id ?? $workOrder->tenant_id,
                'work_order_id' => $workOrder->id,
                'asset_id' => $asset->id,
                'activity_code' => 'ACT-'.strtoupper(Str::random(6)),
                'activity_type' => $workOrder->maintenance_type === 'preventive' ? 'inspection' : 'repair',
                'description' => $dto->resolutionNotes ?: 'Completed maintenance execution',
                'performed_by' => $actorUuid,
                'duration_hours' => $dto->downtimeHours,
                'labor_cost' => $dto->laborCost,
                'spare_parts_cost' => $dto->partsCost,
                'spare_parts_consumed' => $dto->partsConsumed,
                'status' => 'completed',
                'completed_at' => $completedAt,
            ]);

            $assetUpdates = [
                'status' => 'in_service',
                'condition' => $dto->conditionAfterMaintenance,
            ];

            // If capitalizable upgrade, increase asset capitalized cost, depreciable base, and carrying amount
            if ($dto->capitalizeAsUpgrade && $totalCost > 0.0) {
                $newCapCost = round((float) $asset->capitalized_cost + $totalCost, 2);
                $newCarrying = round((float) $asset->carrying_amount + $totalCost, 2);
                $newDeprBase = max(0.0, round($newCarrying - (float) $asset->salvage_value, 2));

                $assetUpdates['capitalized_cost'] = $newCapCost;
                $assetUpdates['carrying_amount'] = $newCarrying;
                $assetUpdates['depreciable_base'] = $newDeprBase;
                $assetUpdates['is_fully_depreciated'] = false;
            }

            $asset->update($assetUpdates);

            if ($workOrder->plan_id !== null) {
                $plan = MaintenancePlan::find($workOrder->plan_id);
                if ($plan !== null) {
                    $freq = max(1, (int) $plan->frequency_days);
                    $plan->update([
                        'last_executed_date' => date('Y-m-d'),
                        'next_due_date' => date('Y-m-d', strtotime("+{$freq} days")),
                    ]);
                }
            }

            $this->auditLedger->record(
                action: 'MAINTENANCE_WORK_ORDER_COMPLETED',
                model: $workOrder,
                payloadBefore: $beforeWo,
                payloadAfter: $workOrder->fresh()->toArray(),
                moduleCode: 'MODULE_16_EAM'
            );

            if ($dto->capitalizeAsUpgrade && $totalCost > 0.0) {
                $this->auditLedger->record(
                    action: 'ASSET_UPGRADED_VIA_MAINTENANCE',
                    model: $asset,
                    payloadBefore: $beforeAsset,
                    payloadAfter: $asset->fresh()->toArray(),
                    moduleCode: 'MODULE_16_EAM'
                );
            }

            $completedEvent = new MaintenanceCompletedEvent(
                workOrderId: (string) $workOrder->id,
                workOrderNumber: (string) $workOrder->work_order_number,
                assetId: (string) $asset->id,
                totalCost: $totalCost,
                downtimeHours: $dto->downtimeHours,
                capitalizedAsUpgrade: $dto->capitalizeAsUpgrade,
                completedAt: $completedAt->toIso8601String(),
                tenantId: (string) $workOrder->tenant_id,
                companyId: (string) ($asset->company_id ?? $workOrder->tenant_id)
            );
            $this->dispatcher->publish($completedEvent);
            event($completedEvent);

            return $workOrder->fresh();
        });
    }
}
