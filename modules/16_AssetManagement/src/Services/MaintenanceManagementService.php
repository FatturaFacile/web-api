<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Services;

use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use Carbon\CarbonImmutable;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AssetManagement\Contracts\AssetAccountingServiceInterface;
use Modules\AssetManagement\Contracts\MaintenanceManagementServiceInterface;
use Modules\AssetManagement\DTOs\CompleteMaintenanceWorkOrderDTO;
use Modules\AssetManagement\DTOs\CreateMaintenanceWorkOrderDTO;
use Modules\AssetManagement\Events\AssetMaintenanceCreatedEvent;
use Modules\AssetManagement\Events\MaintenanceCompletedEvent;
use Modules\AssetManagement\Events\MaintenanceWorkOrderCreatedEvent;
use Modules\AssetManagement\Exceptions\AssetOperationException;
use Modules\AssetManagement\Models\AssetMeterReading;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Models\MaintenanceActivity;
use Modules\AssetManagement\Models\MaintenancePlan;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;
use Modules\AssetManagement\Models\WorkOrderPart;
use Modules\AssetManagement\Support\Money;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\WorkflowDefinition;

/**
 * Maintenance management. Spare parts leave the stock through the public StockService of the inventory; a work order
 * capitalized as improvement raises the cost of the asset books and is posted through the asset accounting service.
 */
final readonly class MaintenanceManagementService implements MaintenanceManagementServiceInterface
{
    /** Estimated cost from which LARGE_MAINTENANCE_APPROVAL applies, when defined. */
    public const string ApprovalThreshold = '10000';

    public const array OpenStatuses = ['scheduled', 'pending_approval', 'in_progress'];

    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private AssetAccountingServiceInterface $accountingService,
        private AssetBookService $books,
        private StockService $stock,
        private ?WorkflowEngineInterface $workflowEngine = null
    ) {}

    public function createMaintenancePlan(array $data, ?string $actorUserId = null): MaintenancePlan
    {
        return DB::transaction(function () use ($data) {
            $plan = new MaintenancePlan([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $data['tenant_id'],
                'plan_code' => $data['plan_code'] ?? 'MPLAN-'.strtoupper(Str::random(6)),
                'is_active' => true,
            ]);
            $this->fillPlan($plan, $data);
            $plan->save();

            $this->auditLedger->record('MAINTENANCE_PLAN_CREATED', $plan, null, $plan->toArray(), 'MODULE_16_EAM');

            return $plan->fresh();
        });
    }

    public function updateMaintenancePlan(MaintenancePlan $plan, array $data, ?string $actorUserId = null): MaintenancePlan
    {
        return DB::transaction(function () use ($plan, $data) {
            $locked = MaintenancePlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $before = $locked->toArray();
            $this->fillPlan($locked, $data);
            if (array_key_exists('is_active', $data)) {
                $locked->is_active = (bool) $data['is_active'];
            }
            $locked->save();

            $this->auditLedger->record('MAINTENANCE_PLAN_UPDATED', $locked, $before, $locked->toArray(), 'MODULE_16_EAM');

            return $locked->fresh();
        });
    }

    public function duePlans(string $tenantId, ?string $asOf = null): Collection
    {
        $day = CarbonImmutable::parse($asOf ?? now()->toDateString());

        return MaintenancePlan::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('asset_id')
            ->orderBy('next_due_date')
            ->limit(500)
            ->get()
            ->filter(function (MaintenancePlan $plan) use ($day): bool {
                $timeDue = in_array($plan->trigger_type, ['time', 'time_or_meter'], true)
                    && $plan->next_due_date !== null
                    && CarbonImmutable::parse($plan->next_due_date->format('Y-m-d'))->subDays((int) $plan->lead_time_days)->lte($day);

                $meterDue = in_array($plan->trigger_type, ['meter', 'time_or_meter'], true)
                    && $plan->next_due_meter !== null
                    && Money::compare($this->latestReading((string) $plan->asset_id, (string) $plan->meter_code) ?? '0', $plan->next_due_meter) >= 0;

                return $timeDue || $meterDue;
            })
            ->values();
    }

    public function generateWorkOrderFromPlan(MaintenancePlan $plan, ?string $scheduledDate = null, ?string $actorUserId = null): MaintenanceWorkOrder
    {
        if ($plan->asset_id === null || ! $plan->is_active) {
            throw new AssetOperationException('Only an active plan of a specific asset can open a work order.', 'plan');
        }

        $open = MaintenanceWorkOrder::where('tenant_id', $plan->tenant_id)->where('plan_id', $plan->id)->whereIn('status', self::OpenStatuses)->exists();
        if ($open) {
            throw new AssetOperationException("Plan [{$plan->plan_code}] already has an open work order.", 'plan');
        }

        return $this->createWorkOrder(new CreateMaintenanceWorkOrderDTO(
            tenantId: (string) $plan->tenant_id,
            assetId: (string) $plan->asset_id,
            maintenanceType: (string) $plan->maintenance_type === 'calibration' ? 'preventive' : (string) $plan->maintenance_type,
            priority: 'medium',
            scheduledDate: $scheduledDate ?? $plan->next_due_date?->format('Y-m-d'),
            planId: (string) $plan->id,
            problemDescription: "Preventive maintenance: {$plan->name}",
            estimatedCost: (string) $plan->estimated_cost,
        ), $actorUserId);
    }

    public function createWorkOrder(CreateMaintenanceWorkOrderDTO $dto, ?string $actorUserId = null): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $asset = FixedAsset::where('tenant_id', $dto->tenantId)->where('id', $dto->assetId)->lockForUpdate()->firstOrFail();

            if (in_array($asset->status, AssetLifecycleService::ClosedStatuses, true)) {
                throw new AssetOperationException("Cannot create a maintenance work order for disposed asset [{$asset->asset_number}].", 'asset_id');
            }

            $scheduledDate = $dto->scheduledDate ?: now()->toDateString();
            $estimated = Money::round($dto->estimatedCost);
            $number = 'MWO-'.date('Ymd').'-'.strtoupper(Str::random(6));

            $workOrder = MaintenanceWorkOrder::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $dto->tenantId,
                'asset_id' => $asset->id,
                'plan_id' => $dto->planId,
                'work_order_number' => $number,
                'maintenance_type' => $dto->maintenanceType,
                'priority' => $dto->priority,
                'status' => 'scheduled',
                'scheduled_date' => $scheduledDate,
                'estimated_cost' => $estimated,
                'total_cost' => '0.00',
                'assigned_technician_id' => $dto->assignedTechnicianId,
                'problem_description' => $dto->problemDescription,
            ]);

            if (Money::compare($estimated, self::ApprovalThreshold) >= 0 && $this->workflowEngine !== null
                && WorkflowDefinition::where('tenant_id', $dto->tenantId)->where('code', 'LARGE_MAINTENANCE_APPROVAL')->where('is_active', true)->exists()) {
                $instance = $this->workflowEngine->start(new StartWorkflowDTO(
                    workflowCode: 'LARGE_MAINTENANCE_APPROVAL',
                    approvable: $workOrder,
                    contextSnapshot: ['work_order_number' => $number, 'estimated_cost' => $estimated],
                    tenantId: $dto->tenantId,
                    companyId: $dto->tenantId,
                    initiatorUserId: $this->actorUuid($actorUserId)
                ));
                $workOrder->lockByWorkflow($instance);
            }

            if (in_array($dto->maintenanceType, ['corrective', 'emergency'], true) || $dto->priority === 'critical') {
                $asset->update(['status' => 'under_maintenance', 'metadata' => [...($asset->metadata ?? []), 'status_before_maintenance' => $asset->status]]);
            }

            $this->auditLedger->record('MAINTENANCE_WORK_ORDER_CREATED', $workOrder, null, $workOrder->fresh()?->toArray(), 'MODULE_16_EAM');
            $companyId = (string) ($asset->company_id ?? $dto->tenantId);

            $this->dispatcher->publishAndDispatch(new AssetMaintenanceCreatedEvent(
                workOrderId: (string) $workOrder->id,
                workOrderNumber: $number,
                assetId: (string) $asset->id,
                maintenanceType: $dto->maintenanceType,
                priority: $dto->priority,
                totalCost: Money::toFloat($estimated),
                scheduledDate: $scheduledDate,
                tenantId: $dto->tenantId,
                companyId: $companyId
            ));
            $this->dispatcher->publishAndDispatch(new MaintenanceWorkOrderCreatedEvent(
                workOrderId: (string) $workOrder->id,
                workOrderNumber: $number,
                assetId: (string) $asset->id,
                workOrderType: $dto->maintenanceType,
                priority: $dto->priority,
                estimatedCost: Money::toFloat($estimated),
                scheduledDate: $scheduledDate,
                tenantId: $dto->tenantId,
                companyId: $companyId
            ));

            return $workOrder->fresh();
        });
    }

    public function startWorkOrder(MaintenanceWorkOrder $workOrder, bool $stopsAsset = true, ?string $actorUserId = null): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($workOrder, $stopsAsset) {
            $locked = $this->lockWorkOrder($workOrder);

            if ($locked->status !== 'scheduled') {
                throw new AssetOperationException("Work order [{$locked->work_order_number}] cannot be started (status: {$locked->status}).", 'status');
            }

            $before = $locked->toArray();
            $locked->update([
                'status' => 'in_progress',
                'started_at' => now(),
                'downtime_started_at' => $stopsAsset ? now() : null,
            ]);

            if ($stopsAsset) {
                $asset = FixedAsset::where('tenant_id', $locked->tenant_id)->whereKey($locked->asset_id)->lockForUpdate()->firstOrFail();
                if ($asset->status !== 'under_maintenance') {
                    $asset->update(['status' => 'under_maintenance', 'metadata' => [...($asset->metadata ?? []), 'status_before_maintenance' => $asset->status]]);
                }
            }

            $this->auditLedger->record('MAINTENANCE_WORK_ORDER_STARTED', $locked, $before, $locked->toArray(), 'MODULE_16_EAM');

            return $locked->fresh();
        });
    }

    public function consumePart(MaintenanceWorkOrder $workOrder, string $productId, string|int|float $quantity, ?string $warehouseId = null, ?string $actorUserId = null): WorkOrderPart
    {
        return DB::transaction(function () use ($workOrder, $productId, $quantity, $warehouseId, $actorUserId) {
            $locked = $this->lockWorkOrder($workOrder);

            if (! in_array($locked->status, ['scheduled', 'in_progress'], true)) {
                throw new AssetOperationException("Parts cannot be issued to work order [{$locked->work_order_number}] (status: {$locked->status}).", 'status');
            }

            $quantity = Money::round($quantity, 4);
            if (! Money::isPositive($quantity)) {
                throw new AssetOperationException('The quantity must be greater than zero.', 'quantity');
            }

            $product = Product::where('tenant_id', $locked->tenant_id)->findOrFail($productId);
            $warehouse = $warehouseId !== null ? Warehouse::where('tenant_id', $locked->tenant_id)->findOrFail($warehouseId) : null;
            $unitCost = Money::round($product->cost_price ?? '0', 4);

            $movement = $product->track_inventory
                ? $this->stock->record((string) $product->id, 'out', (float) $quantity, $locked, $warehouse, (float) $unitCost, "Maintenance work order {$locked->work_order_number}")
                : null;

            $part = WorkOrderPart::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $locked->tenant_id,
                'work_order_id' => $locked->id,
                'product_id' => $product->id,
                'warehouse_id' => $movement !== null ? $movement->warehouse_id : $warehouse?->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => Money::round(Money::mul($quantity, $unitCost)),
                'stock_movement_id' => $movement?->id,
                'consumed_by_user_id' => $this->actorUuid($actorUserId),
                'consumed_at' => now(),
            ]);

            $locked->update(['parts_cost' => Money::round(Money::add($locked->parts_cost, $part->total_cost))]);

            return $part;
        });
    }

    public function completeWorkOrder(CompleteMaintenanceWorkOrderDTO $dto, ?string $actorUserId = null): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $workOrder = MaintenanceWorkOrder::whereKey($dto->workOrderId)->lockForUpdate()->firstOrFail();

            if (in_array($workOrder->status, ['completed', 'cancelled'], true)) {
                throw new AssetOperationException("Maintenance work order [{$workOrder->work_order_number}] is already {$workOrder->status}.", 'status');
            }
            if ($workOrder->is_locked) {
                throw new AssetOperationException("Maintenance work order [{$workOrder->work_order_number}] is locked awaiting workflow approval.", 'status');
            }

            $beforeWorkOrder = $workOrder->toArray();
            $asset = FixedAsset::where('tenant_id', $workOrder->tenant_id)->whereKey($workOrder->asset_id)->lockForUpdate()->firstOrFail();
            $beforeAsset = $asset->toArray();
            $completedAt = now();

            $partsCost = Money::round(Money::add($workOrder->parts_cost, $dto->partsCost));
            $totalCost = Money::round(Money::add(Money::add($dto->laborCost, $partsCost), $dto->externalServiceCost));
            $downtime = Money::isPositive($dto->downtimeHours) || $workOrder->downtime_started_at === null
                ? Money::round($dto->downtimeHours)
                : Money::round(Money::div((string) $workOrder->downtime_started_at->diffInMinutes($completedAt), '60'));

            $workOrder->update([
                'status' => 'completed',
                'completed_at' => $completedAt,
                'downtime_ended_at' => $workOrder->downtime_started_at !== null ? $completedAt : null,
                'downtime_hours' => $downtime,
                'labor_cost' => Money::round($dto->laborCost),
                'parts_cost' => $partsCost,
                'external_service_cost' => Money::round($dto->externalServiceCost),
                'total_cost' => $totalCost,
                'capitalize_as_upgrade' => $dto->capitalizeAsUpgrade,
                'condition_after_maintenance' => $dto->conditionAfterMaintenance,
                'resolution_notes' => $dto->resolutionNotes,
                'parts_consumed' => $dto->partsConsumed,
                'meter_reading_at_completion' => $dto->meterReading !== null ? Money::round($dto->meterReading) : null,
            ]);

            MaintenanceActivity::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $workOrder->tenant_id,
                'company_id' => $asset->company_id ?? $workOrder->tenant_id,
                'work_order_id' => $workOrder->id,
                'asset_id' => $asset->id,
                'activity_code' => 'ACT-'.strtoupper(Str::random(6)),
                'activity_type' => $workOrder->maintenance_type === 'preventive' ? 'inspection' : 'repair',
                'description' => Str::limit($dto->resolutionNotes ?: 'Completed maintenance execution', 250, ''),
                'performed_by' => $this->actorUuid($actorUserId) ?? $workOrder->assigned_technician_id,
                'duration_hours' => $downtime,
                'labor_cost' => Money::round($dto->laborCost),
                'spare_parts_cost' => $partsCost,
                'spare_parts_consumed' => $dto->partsConsumed,
                'status' => 'completed',
                'completed_at' => $completedAt,
            ]);

            $metadata = $asset->metadata ?? [];
            $statusBefore = $metadata['status_before_maintenance'] ?? null;
            unset($metadata['status_before_maintenance']);

            $assetUpdates = ['condition' => $dto->conditionAfterMaintenance, 'metadata' => $metadata];
            if ($asset->status === 'under_maintenance' && ! $this->hasOtherOpenStoppingWorkOrders($workOrder)) {
                $assetUpdates['status'] = is_string($statusBefore) && $statusBefore !== 'under_maintenance' ? $statusBefore : 'in_service';
            }

            $capitalize = $dto->capitalizeAsUpgrade && Money::isPositive($totalCost);
            if ($capitalize) {
                if (! in_array($asset->status, AssetLifecycleService::CapitalizedStatuses, true)) {
                    throw new AssetOperationException('Only the maintenance of a capitalized asset can be capitalized as an improvement.', 'capitalize_as_upgrade');
                }

                $newCarrying = Money::round(Money::add($asset->carrying_amount, $totalCost));
                $this->books->rebaseOnRemainingLife($asset, $newCarrying, $completedAt->toDateString());
                $assetUpdates['capitalized_cost'] = Money::round(Money::add($asset->capitalized_cost, $totalCost));
                $assetUpdates['carrying_amount'] = $newCarrying;
                $assetUpdates['depreciable_base'] = $asset->depreciable_base;
                $assetUpdates['useful_life_months'] = $asset->useful_life_months;
                $assetUpdates['metadata'] = [...$metadata, 'life_months_elapsed' => ($asset->metadata ?? [])['life_months_elapsed'] ?? 0];
                $assetUpdates['is_fully_depreciated'] = false;
            }

            $asset->update($assetUpdates);

            if ($capitalize) {
                $this->books->addCost($asset, $totalCost);
                $this->accountingService->postImprovementEntry($workOrder->fresh(), $asset, $totalCost, $actorUserId);
                $this->auditLedger->record('ASSET_UPGRADED_VIA_MAINTENANCE', $asset, $beforeAsset, $asset->fresh()?->toArray(), 'MODULE_16_EAM');
            }

            $this->advancePlan($workOrder, $dto->meterReading);

            $this->auditLedger->record('MAINTENANCE_WORK_ORDER_COMPLETED', $workOrder, $beforeWorkOrder, $workOrder->fresh()?->toArray(), 'MODULE_16_EAM');

            $this->dispatcher->publishAndDispatch(new MaintenanceCompletedEvent(
                workOrderId: (string) $workOrder->id,
                workOrderNumber: (string) $workOrder->work_order_number,
                assetId: (string) $asset->id,
                totalCost: Money::toFloat($totalCost),
                downtimeHours: Money::toFloat($downtime),
                capitalizedAsUpgrade: $capitalize,
                completedAt: $completedAt->toIso8601String(),
                tenantId: (string) $workOrder->tenant_id,
                companyId: (string) ($asset->company_id ?? $workOrder->tenant_id)
            ));

            return $workOrder->fresh();
        });
    }

    public function cancelWorkOrder(MaintenanceWorkOrder $workOrder, ?string $reason = null, ?string $actorUserId = null): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($workOrder, $reason) {
            $locked = $this->lockWorkOrder($workOrder);

            if (in_array($locked->status, ['completed', 'cancelled'], true)) {
                throw new AssetOperationException("Work order [{$locked->work_order_number}] is already {$locked->status}.", 'status');
            }

            $hasParts = WorkOrderPart::where('tenant_id', $locked->tenant_id)->where('work_order_id', $locked->id)->exists();
            if ($hasParts) {
                throw new AssetOperationException("Work order [{$locked->work_order_number}] has issued spare parts: complete it instead.", 'status');
            }

            $before = $locked->toArray();
            $locked->update(['status' => 'cancelled', 'cancelled_at' => now(), 'resolution_notes' => $reason, 'is_locked' => false]);

            $asset = FixedAsset::where('tenant_id', $locked->tenant_id)->whereKey($locked->asset_id)->lockForUpdate()->first();
            if ($asset !== null && $asset->status === 'under_maintenance' && ! $this->hasOtherOpenStoppingWorkOrders($locked)) {
                $metadata = $asset->metadata ?? [];
                $statusBefore = $metadata['status_before_maintenance'] ?? 'in_service';
                unset($metadata['status_before_maintenance']);
                $asset->update(['status' => $statusBefore, 'metadata' => $metadata]);
            }

            $this->auditLedger->record('MAINTENANCE_WORK_ORDER_CANCELLED', $locked, $before, $locked->toArray(), 'MODULE_16_EAM');

            return $locked->fresh();
        });
    }

    public function recordMeterReading(FixedAsset $asset, string $meterCode, string|int|float $value, ?string $readAt = null, ?string $notes = null, ?string $actorUserId = null): AssetMeterReading
    {
        return DB::transaction(function () use ($asset, $meterCode, $value, $readAt, $notes, $actorUserId) {
            $locked = FixedAsset::where('tenant_id', $asset->tenant_id)->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $reading = Money::round($value);
            $latest = $this->latestReading((string) $locked->id, $meterCode);

            if ($latest !== null && Money::compare($reading, $latest) < 0) {
                throw new AssetOperationException("The reading of meter [{$meterCode}] cannot be lower than the previous one ({$latest}).", 'reading_value');
            }

            return AssetMeterReading::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $locked->tenant_id,
                'asset_id' => $locked->id,
                'meter_code' => $meterCode,
                'reading_value' => $reading,
                'read_at' => $readAt ?? now(),
                'source' => 'manual',
                'recorded_by_user_id' => $this->actorUuid($actorUserId),
                'notes' => $notes,
            ]);
        });
    }

    public function maintenanceSummary(FixedAsset $asset): array
    {
        $base = MaintenanceWorkOrder::where('tenant_id', $asset->tenant_id)->where('asset_id', $asset->id);
        $completed = (clone $base)->where('status', 'completed');

        $correctiveDates = (clone $completed)->whereIn('maintenance_type', ['corrective', 'emergency'])
            ->orderBy('completed_at')
            ->pluck('completed_at')
            ->map(static fn ($date): CarbonImmutable => CarbonImmutable::parse($date))
            ->values();

        $mtbfDays = null;
        if ($correctiveDates->count() > 1) {
            $mtbfDays = round($correctiveDates->first()->diffInDays($correctiveDates->last()) / ($correctiveDates->count() - 1), 1);
        }

        return [
            'asset_id' => (string) $asset->id,
            'work_orders_total' => (clone $base)->count(),
            'work_orders_open' => (clone $base)->whereIn('status', self::OpenStatuses)->count(),
            'work_orders_completed' => (clone $completed)->count(),
            'labor_cost' => Money::round((string) (clone $completed)->sum('labor_cost')),
            'parts_cost' => Money::round((string) (clone $completed)->sum('parts_cost')),
            'external_service_cost' => Money::round((string) (clone $completed)->sum('external_service_cost')),
            'total_cost' => Money::round((string) (clone $completed)->sum('total_cost')),
            'capitalized_cost' => Money::round((string) (clone $completed)->where('capitalize_as_upgrade', true)->sum('total_cost')),
            'downtime_hours' => Money::round((string) (clone $completed)->sum('downtime_hours')),
            'last_completed_at' => (clone $completed)->max('completed_at'),
            'mean_days_between_failures' => $mtbfDays,
        ];
    }

    /**
     * Copies the validated plan attributes and computes the next due date and meter.
     *
     * @param  array<string, mixed>  $data
     */
    private function fillPlan(MaintenancePlan $plan, array $data): void
    {
        foreach (['asset_id', 'asset_class_id', 'name', 'maintenance_type', 'trigger_type', 'meter_code', 'lead_time_days', 'checklist', 'translations'] as $field) {
            if (array_key_exists($field, $data)) {
                $plan->{$field} = $data[$field];
            }
        }

        $plan->maintenance_type ??= 'preventive';
        $plan->trigger_type ??= 'time';
        $plan->frequency_days = (int) ($data['frequency_days'] ?? $plan->frequency_days ?? 90);

        foreach (['estimated_duration_hours', 'estimated_cost', 'meter_interval_units'] as $field) {
            if (array_key_exists($field, $data)) {
                $plan->{$field} = $data[$field] !== null ? Money::round($data[$field]) : null;
            }
        }

        if (array_key_exists('next_due_date', $data) || $plan->next_due_date === null) {
            $plan->next_due_date = $data['next_due_date'] ?? now()->addDays($plan->frequency_days)->toDateString();
        }

        if ($plan->trigger_type !== 'time' && $plan->meter_interval_units !== null && ($plan->next_due_meter === null || array_key_exists('meter_interval_units', $data))) {
            $latest = $plan->asset_id !== null ? $this->latestReading((string) $plan->asset_id, (string) $plan->meter_code) : null;
            $plan->last_meter_reading = $latest;
            $plan->next_due_meter = Money::round(Money::add($latest ?? '0', $plan->meter_interval_units));
        }
    }

    /**
     * Moves the plan of a completed work order to its next due date and meter.
     */
    private function advancePlan(MaintenanceWorkOrder $workOrder, string|int|float|null $meterAtCompletion): void
    {
        if ($workOrder->plan_id === null) {
            return;
        }

        $plan = MaintenancePlan::where('tenant_id', $workOrder->tenant_id)->whereKey($workOrder->plan_id)->lockForUpdate()->first();
        if ($plan === null) {
            return;
        }

        $updates = [
            'last_executed_date' => now()->toDateString(),
            'next_due_date' => now()->addDays(max(1, (int) $plan->frequency_days))->toDateString(),
        ];

        if ($plan->meter_interval_units !== null && $plan->meter_code !== null) {
            $meter = $meterAtCompletion !== null ? Money::round($meterAtCompletion) : $this->latestReading((string) $plan->asset_id, (string) $plan->meter_code);
            if ($meter !== null) {
                $updates['last_meter_reading'] = $meter;
                $updates['next_due_meter'] = Money::round(Money::add($meter, $plan->meter_interval_units));
            }
        }

        $plan->update($updates);
    }

    /**
     * The latest reading of an asset meter, null when none.
     */
    private function latestReading(string $assetId, string $meterCode): ?string
    {
        $value = AssetMeterReading::where('asset_id', $assetId)->where('meter_code', $meterCode)->max('reading_value');

        return $value !== null ? Money::round((string) $value) : null;
    }

    /**
     * Whether other open work orders keep the asset stopped.
     */
    private function hasOtherOpenStoppingWorkOrders(MaintenanceWorkOrder $workOrder): bool
    {
        return MaintenanceWorkOrder::where('tenant_id', $workOrder->tenant_id)
            ->where('asset_id', $workOrder->asset_id)
            ->where('id', '!=', $workOrder->id)
            ->where(function ($query): void {
                $query->where('status', 'in_progress')->whereNotNull('downtime_started_at')
                    ->orWhere(fn ($open) => $open->whereIn('status', self::OpenStatuses)->where(fn ($kind) => $kind->whereIn('maintenance_type', ['corrective', 'emergency'])->orWhere('priority', 'critical')));
            })
            ->exists();
    }

    /**
     * The work order with its row lock.
     */
    private function lockWorkOrder(MaintenanceWorkOrder $workOrder): MaintenanceWorkOrder
    {
        return MaintenanceWorkOrder::where('tenant_id', $workOrder->tenant_id)->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * The actor id when it is a valid uuid, else null.
     */
    private function actorUuid(?string $actorUserId): ?string
    {
        return ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : null;
    }
}
