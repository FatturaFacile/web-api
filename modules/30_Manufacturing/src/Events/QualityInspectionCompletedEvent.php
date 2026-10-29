<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

final class QualityInspectionCompletedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $inspectionId,
        public string $inspectionNumber,
        public string $qualityPlanId,
        public ?string $productionOrderId,
        public string $status,
        public int $defectCount,
        string $tenantId,
        ?string $companyId = null,
        ?string $eventId = null,
        ?string $occurredAt = null
    ) {
        parent::__construct($tenantId, $companyId, $eventId, $occurredAt);
    }

    public function getAggregateType(): string
    {
        return 'mfg_quality_inspection';
    }

    public function getAggregateId(): string
    {
        return $this->inspectionId;
    }

    public function getEventName(): string
    {
        return 'mfg.quality.inspection_completed';
    }

    public function toPayload(): array
    {
        return [
            'inspection_id' => $this->inspectionId,
            'inspection_number' => $this->inspectionNumber,
            'quality_plan_id' => $this->qualityPlanId,
            'production_order_id' => $this->productionOrderId,
            'status' => $this->status,
            'defect_count' => $this->defectCount,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
