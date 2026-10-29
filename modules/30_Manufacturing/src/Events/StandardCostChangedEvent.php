<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

final class StandardCostChangedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $mfgItemId,
        public float $oldCost,
        public float $newCost,
        public string $reason,
        string $tenantId,
        ?string $companyId = null,
        ?string $eventId = null,
        ?string $occurredAt = null
    ) {
        parent::__construct($tenantId, $companyId, $eventId, $occurredAt);
    }

    public function getAggregateType(): string
    {
        return 'mfg_item';
    }

    public function getAggregateId(): string
    {
        return $this->mfgItemId;
    }

    public function getEventName(): string
    {
        return 'mfg.costing.standard_cost_changed';
    }

    public function toPayload(): array
    {
        return [
            'mfg_item_id' => $this->mfgItemId,
            'old_cost' => $this->oldCost,
            'new_cost' => $this->newCost,
            'reason' => $this->reason,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
