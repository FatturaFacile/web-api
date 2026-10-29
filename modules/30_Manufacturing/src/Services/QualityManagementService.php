<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\EventBusInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Contracts\QualityManagementServiceInterface;
use Modules\Manufacturing\DTOs\RecordQualityInspectionDTO;
use Modules\Manufacturing\Events\NonConformanceDetectedEvent;
use Modules\Manufacturing\Events\QualityInspectionCompletedEvent;
use Modules\Manufacturing\Models\MfgQualityCharacteristic;
use Modules\Manufacturing\Models\MfgQualityInspection;
use Modules\Manufacturing\Models\MfgQualityNonConformance;
use Modules\Manufacturing\Models\MfgQualityPlan;

final readonly class QualityManagementService implements QualityManagementServiceInterface
{
    public function __construct(
        private EventBusInterface $eventBus,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function executeInspection(RecordQualityInspectionDTO $dto): MfgQualityInspection
    {
        return DB::transaction(function () use ($dto): MfgQualityInspection {
            $plan = MfgQualityPlan::with('characteristics')->findOrFail($dto->qualityPlanId);
            $inspectionNumber = 'INS-'.strtoupper((string) Str::random(8));

            $defectCount = $dto->defectCount;
            $hasCriticalDefect = false;

            // Evaluate characteristics against tolerance criteria
            foreach ($dto->characteristicResults as $result) {
                if (! ($result['is_conforming'] ?? true)) {
                    $defectCount++;
                    $char = MfgQualityCharacteristic::find($result['characteristic_id'] ?? null);
                    if ($char && $char->is_critical) {
                        $hasCriticalDefect = true;
                    }
                }
            }

            $status = ($defectCount === 0) ? 'passed' : ($hasCriticalDefect ? 'failed' : 'conditional_pass');

            $inspection = MfgQualityInspection::create([
                'tenant_id' => $dto->tenantId,
                'inspection_number' => $inspectionNumber,
                'quality_plan_id' => $dto->qualityPlanId,
                'production_order_id' => $dto->productionOrderId,
                'operation_id' => $dto->operationId,
                'inspector_id' => $dto->inspectorId,
                'sample_size' => $dto->sampleSize,
                'defect_count' => $defectCount,
                'status' => $status,
                'inspected_at' => now(),
                'notes' => $dto->notes,
                'characteristic_results' => $dto->characteristicResults,
            ]);

            $this->auditLedger->record(
                'mfg.quality.inspection_completed',
                $inspection,
                null,
                ['status' => $status, 'defects' => $defectCount],
                '30_Manufacturing'
            );

            $this->eventBus->dispatch(new QualityInspectionCompletedEvent(
                inspectionId: $inspection->id,
                inspectionNumber: $inspection->inspectionNumber ?? $inspectionNumber,
                qualityPlanId: $inspection->quality_plan_id,
                productionOrderId: $inspection->production_order_id,
                status: $status,
                defectCount: $defectCount,
                tenantId: $dto->tenantId
            ));

            // Automatically open Non-Conformance report if inspection failed
            if ($status === 'failed') {
                $this->recordNonConformance(
                    tenantId: $dto->tenantId,
                    qualityInspectionId: $inspection->id,
                    productionOrderId: $dto->productionOrderId,
                    severity: 'critical',
                    defectType: 'Critical Quality Tolerance Failure',
                    description: "Failed inspection {$inspectionNumber} with {$defectCount} defect(s).",
                    disposition: 'rework'
                );
            }

            return $inspection;
        });
    }

    public function recordNonConformance(
        string $tenantId,
        ?string $qualityInspectionId,
        ?string $productionOrderId,
        string $severity,
        string $defectType,
        string $description,
        string $disposition = 'investigating'
    ): MfgQualityNonConformance {
        $ncNumber = 'NCR-'.strtoupper((string) Str::random(8));

        $nc = MfgQualityNonConformance::create([
            'tenant_id' => $tenantId,
            'nc_number' => $ncNumber,
            'quality_inspection_id' => $qualityInspectionId,
            'production_order_id' => $productionOrderId,
            'severity' => $severity,
            'defect_type' => $defectType,
            'description' => $description,
            'disposition' => $disposition,
            'status' => 'open',
        ]);

        $this->auditLedger->record('mfg.quality.ncr_created', $nc, null, ['nc_number' => $nc->nc_number, 'severity' => $severity], '30_Manufacturing');

        $this->eventBus->dispatch(new NonConformanceDetectedEvent(
            nonConformanceId: $nc->id,
            ncNumber: $nc->nc_number,
            qualityInspectionId: $qualityInspectionId,
            productionOrderId: $productionOrderId,
            severity: $severity,
            defectType: $defectType,
            tenantId: $tenantId
        ));

        return $nc;
    }

    public function resolveNonConformance(string $ncId, string $resolution, string $resolvedBy): MfgQualityNonConformance
    {
        $nc = MfgQualityNonConformance::findOrFail($ncId);
        $nc->update([
            'status' => 'resolved',
            'corrective_action' => $resolution,
            'resolved_by' => $resolvedBy,
            'resolved_at' => now(),
        ]);

        $this->auditLedger->record('mfg.quality.ncr_resolved', $nc, null, ['corrective_action' => $resolution], '30_Manufacturing');

        return $nc;
    }
}
