<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

final class NonConformanceDetectedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $nonConformanceId,
        public string $ncNumber,
        public ?string $qualityInspectionId,
        public ?string $productionOrderId,
        public string $severity,
        public string $defectType,
        string $tenantId,
        ?string $companyId = null,
        ?string $eventId = null,
        ?string $occurredAt = null
    ) {
        parent::__construct($tenantId, $companyId, $eventId, $occurredAt);
    }

    public function getAggregateType(): string
    {
        return 'mfg_quality_non_conformance';
    }

    public function getAggregateId(): string
    {
        return $this->nonConformanceId;
    }

    public function getEventName(): string
    {
        return 'mfg.quality.non_conformance_detected';
    }

    public function toPayload(): array
    {
        return [
            'non_conformance_id' => $this->nonConformanceId,
            'nc_number' => $this->ncNumber,
            'quality_inspection_id' => $this->qualityInspectionId,
            'production_order_id' => $this->productionOrderId,
            'severity' => $this->severity,
            'defect_type' => $this->defectType,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
