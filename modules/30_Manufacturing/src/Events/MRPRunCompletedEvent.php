<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

final class MRPRunCompletedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $mrpRunId,
        public string $runNumber,
        public string $runType,
        public int $totalPlannedOrders,
        public int $totalShortages,
        string $tenantId,
        ?string $companyId = null,
        ?string $eventId = null,
        ?string $occurredAt = null
    ) {
        parent::__construct($tenantId, $companyId, $eventId, $occurredAt);
    }

    public function getAggregateType(): string
    {
        return 'mfg_mrp_run';
    }

    public function getAggregateId(): string
    {
        return $this->mrpRunId;
    }

    public function getEventName(): string
    {
        return 'mfg.mrp.completed';
    }

    public function toPayload(): array
    {
        return [
            'mrp_run_id' => $this->mrpRunId,
            'run_number' => $this->runNumber,
            'run_type' => $this->runType,
            'total_planned_orders' => $this->totalPlannedOrders,
            'total_shortages' => $this->totalShortages,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
