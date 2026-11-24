<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * A quality inspection passed or failed.
 */
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

    /**
     * Aggregate type of the event.
     */
    public function getAggregateType(): string
    {
        return 'mfg_quality_inspection';
    }

    /**
     * Aggregate id of the event.
     */
    public function getAggregateId(): string
    {
        return $this->inspectionId;
    }

    /**
     * Name of the event in the outbox.
     */
    public function getEventName(): string
    {
        return 'mfg.quality.inspection_completed';
    }

    /**
     * Payload of the event.
     *
     * @return array<string, mixed>
     */
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
