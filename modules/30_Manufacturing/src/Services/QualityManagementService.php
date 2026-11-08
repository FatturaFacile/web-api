<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Contracts\QualityManagementServiceInterface;
use Modules\Manufacturing\DTOs\RecordQualityInspectionDTO;
use Modules\Manufacturing\Events\NonConformanceDetectedEvent;
use Modules\Manufacturing\Events\QualityInspectionCompletedEvent;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgMaterialMovement;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgQualityCharacteristic;
use Modules\Manufacturing\Models\MfgQualityInspection;
use Modules\Manufacturing\Models\MfgQualityNonConformance;
use Modules\Manufacturing\Models\MfgQualityPlan;
use Modules\Manufacturing\Support\Money;

/**
 * Quality management. An inspection fails when a critical characteristic is out of tolerance or the defects (entered
 * plus measurements out of tolerance) exceed the defect limit of the plan; a failed inspection raises a non-conformance
 * on the rejected quantity. Non-conformances are closed with a disposition and a corrective action.
 */
final readonly class QualityManagementService implements QualityManagementServiceInterface
{
    /** Dispositions that close a non-conformance. */
    public const array FinalDispositions = ['use_as_is', 'rework', 'scrap', 'return_to_supplier'];

    public function __construct(private EventDispatcher $dispatcher) {}

    public function createPlan(string $tenantId, array $data): MfgQualityPlan
    {
        return DB::transaction(function () use ($tenantId, $data): MfgQualityPlan {
            $plan = MfgQualityPlan::create([
                'tenant_id' => $tenantId,
                'plan_code' => $data['plan_code'],
                'name' => $data['name'],
                'inspection_type' => $data['inspection_type'] ?? 'final',
                'mfg_item_id' => $data['mfg_item_id'] ?? null,
                'description' => $data['description'] ?? null,
                'acceptance_defect_limit' => (int) ($data['acceptance_defect_limit'] ?? 0),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            foreach ((array) ($data['characteristics'] ?? []) as $index => $characteristic) {
                if (isset($characteristic['min_tolerance'], $characteristic['max_tolerance']) && Money::compare($characteristic['max_tolerance'], $characteristic['min_tolerance']) < 0) {
                    throw new ManufacturingOperationException('The upper tolerance must not be below the lower one.', "characteristics.{$index}.max_tolerance");
                }

                MfgQualityCharacteristic::create([
                    'tenant_id' => $tenantId,
                    'quality_plan_id' => $plan->id,
                    'characteristic_name' => $characteristic['characteristic_name'],
                    'target_value' => isset($characteristic['target_value']) ? Money::round($characteristic['target_value'], 4) : null,
                    'min_tolerance' => isset($characteristic['min_tolerance']) ? Money::round($characteristic['min_tolerance'], 4) : null,
                    'max_tolerance' => isset($characteristic['max_tolerance']) ? Money::round($characteristic['max_tolerance'], 4) : null,
                    'uom' => $characteristic['uom'] ?? 'MM',
                    'inspection_method' => $characteristic['inspection_method'] ?? null,
                    'is_critical' => (bool) ($characteristic['is_critical'] ?? false),
                ]);
            }

            return $plan->load('characteristics');
        });
    }

    public function executeInspection(RecordQualityInspectionDTO $dto): MfgQualityInspection
    {
        return DB::transaction(function () use ($dto): MfgQualityInspection {
            $plan = MfgQualityPlan::query()->with('characteristics')->where('tenant_id', $dto->tenantId)->findOrFail($dto->qualityPlanId);
            $order = $dto->productionOrderId !== null ? MfgProductionOrder::query()->where('tenant_id', $dto->tenantId)->findOrFail($dto->productionOrderId) : null;

            if ($dto->productionOrderOperationId !== null && ($order === null || ! MfgProductionOrderOperation::query()->where('production_order_id', $order->id)->whereKey($dto->productionOrderOperationId)->exists())) {
                throw new ManufacturingOperationException('The operation does not belong to the production order.', 'production_order_operation_id');
            }
            if ($dto->materialMovementId !== null && ($order === null || ! MfgMaterialMovement::query()->where('production_order_id', $order->id)->where('movement_type', MfgMaterialMovement::Receipt)->whereKey($dto->materialMovementId)->exists())) {
                throw new ManufacturingOperationException('The receipt does not belong to the production order.', 'material_movement_id');
            }

            $results = [];
            $outOfTolerance = 0;
            $criticalFailure = false;
            foreach ($dto->characteristicResults as $result) {
                $characteristic = $plan->characteristics->firstWhere('id', $result['characteristic_id']);
                if (! $characteristic instanceof MfgQualityCharacteristic) {
                    throw new ManufacturingOperationException('The characteristic does not belong to the inspection plan.', 'characteristic_results');
                }

                $conforming = $characteristic->isWithinTolerance($result['measured_value']);
                if (! $conforming) {
                    $outOfTolerance++;
                    $criticalFailure = $criticalFailure || $characteristic->is_critical;
                }
                $results[] = [
                    'characteristic_id' => (string) $characteristic->id,
                    'characteristic_name' => $characteristic->characteristic_name,
                    'measured_value' => Money::round($result['measured_value'], 4),
                    'min_tolerance' => $characteristic->min_tolerance,
                    'max_tolerance' => $characteristic->max_tolerance,
                    'is_critical' => $characteristic->is_critical,
                    'is_conforming' => $conforming,
                ];
            }

            $defects = $dto->defectCount + $outOfTolerance;
            $failed = $criticalFailure || $defects > $plan->acceptance_defect_limit;
            $inspected = Money::qty($dto->quantityInspected);
            $rejected = Money::qty($dto->quantityRejected ?? ($failed ? $inspected : '0'));
            if (Money::compare($rejected, $inspected) > 0) {
                throw new ManufacturingOperationException('The rejected quantity cannot exceed the quantity inspected.', 'quantity_rejected');
            }

            $inspection = MfgQualityInspection::create([
                'tenant_id' => $dto->tenantId,
                'inspection_number' => 'QI-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                'quality_plan_id' => $plan->id,
                'inspection_point' => $dto->inspectionPoint,
                'production_order_id' => $order?->id,
                'production_order_operation_id' => $dto->productionOrderOperationId,
                'material_movement_id' => $dto->materialMovementId,
                'mfg_item_id' => $order->mfg_item_id ?? $plan->mfg_item_id,
                'inspector_id' => $this->uuidOrNull($dto->inspectorId),
                'sample_size' => $dto->sampleSize,
                'defect_count' => $defects,
                'quantity_inspected' => $inspected,
                'quantity_accepted' => Money::qty(Money::sub($inspected, $rejected)),
                'quantity_rejected' => $rejected,
                'status' => $failed ? 'failed' : 'passed',
                'inspected_at' => now(),
                'notes' => $dto->notes,
                'characteristic_results' => $results,
            ]);

            if ($failed) {
                $this->recordNonConformance($dto->tenantId, [
                    'quality_inspection_id' => $inspection->id,
                    'production_order_id' => $order?->id,
                    'production_order_operation_id' => $dto->productionOrderOperationId,
                    'mfg_item_id' => $inspection->mfg_item_id,
                    'severity' => $criticalFailure ? 'critical' : 'major',
                    'defect_type' => $criticalFailure ? 'critical_characteristic_out_of_tolerance' : 'defects_above_limit',
                    'description' => "Inspection {$inspection->inspection_number} failed: {$defects} defect(s), limit {$plan->acceptance_defect_limit}.",
                    'quantity' => $rejected,
                ], $dto->inspectorId);
            }

            $this->dispatcher->publishAndDispatch(new QualityInspectionCompletedEvent(
                inspectionId: (string) $inspection->id,
                inspectionNumber: $inspection->inspection_number,
                qualityPlanId: (string) $plan->id,
                productionOrderId: $order?->id !== null ? (string) $order->id : null,
                status: $inspection->status,
                defectCount: $defects,
                tenantId: $dto->tenantId
            ));

            return $inspection->load('nonConformances');
        });
    }

    public function recordNonConformance(string $tenantId, array $data, ?string $reportedBy = null): MfgQualityNonConformance
    {
        $nonConformance = MfgQualityNonConformance::create([
            'tenant_id' => $tenantId,
            'nc_number' => 'NC-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
            'quality_inspection_id' => $data['quality_inspection_id'] ?? null,
            'production_order_id' => $data['production_order_id'] ?? null,
            'production_order_operation_id' => $data['production_order_operation_id'] ?? null,
            'mfg_item_id' => $data['mfg_item_id'] ?? null,
            'severity' => $data['severity'] ?? 'major',
            'defect_type' => $data['defect_type'],
            'description' => $data['description'],
            'quantity' => Money::qty($data['quantity'] ?? '0'),
            'disposition' => 'investigating',
            'status' => 'open',
            'reported_by' => $this->uuidOrNull($reportedBy),
        ]);

        $this->dispatcher->publishAndDispatch(new NonConformanceDetectedEvent(
            nonConformanceId: (string) $nonConformance->id,
            ncNumber: $nonConformance->nc_number,
            qualityInspectionId: $nonConformance->quality_inspection_id,
            productionOrderId: $nonConformance->production_order_id,
            severity: $nonConformance->severity,
            defectType: $nonConformance->defect_type,
            tenantId: $tenantId
        ));

        return $nonConformance;
    }

    public function resolveNonConformance(MfgQualityNonConformance $nonConformance, string $disposition, ?string $correctiveAction, ?string $resolvedBy): MfgQualityNonConformance
    {
        return DB::transaction(function () use ($nonConformance, $disposition, $correctiveAction, $resolvedBy): MfgQualityNonConformance {
            $locked = MfgQualityNonConformance::query()->whereKey($nonConformance->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'open') {
                throw new ManufacturingOperationException("Non-conformance [{$locked->nc_number}] is already {$locked->status}.", 'status');
            }
            if (! in_array($disposition, self::FinalDispositions, true)) {
                throw new ManufacturingOperationException('The disposition must be one of: '.implode(', ', self::FinalDispositions).'.', 'disposition');
            }

            $locked->update([
                'disposition' => $disposition,
                'corrective_action' => $correctiveAction,
                'status' => 'closed',
                'resolved_by' => $this->uuidOrNull($resolvedBy),
                'resolved_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    /**
     * The id when it is a UUID (user ids are), else null.
     */
    private function uuidOrNull(?string $id): ?string
    {
        return $id !== null && Str::isUuid($id) ? $id : null;
    }
}
