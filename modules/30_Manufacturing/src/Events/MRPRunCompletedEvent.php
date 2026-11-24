<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * An MRP run completed with its planned orders and shortages.
 */
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

    /**
     * Aggregate type of the event.
     */
    public function getAggregateType(): string
    {
        return 'mfg_mrp_run';
    }

    /**
     * Aggregate id of the event.
     */
    public function getAggregateId(): string
    {
        return $this->mrpRunId;
    }

    /**
     * Name of the event in the outbox.
     */
    public function getEventName(): string
    {
        return 'mfg.mrp.completed';
    }

    /**
     * Payload of the event.
     *
     * @return array<string, mixed>
     */
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
