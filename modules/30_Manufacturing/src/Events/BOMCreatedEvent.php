<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

final class BOMCreatedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $bomId,
        public string $bomNumber,
        public string $mfgItemId,
        public string $revision,
        string $tenantId,
        ?string $companyId = null,
        ?string $eventId = null,
        ?string $occurredAt = null
    ) {
        parent::__construct($tenantId, $companyId, $eventId, $occurredAt);
    }

    public function getAggregateType(): string
    {
        return 'mfg_bom';
    }

    public function getAggregateId(): string
    {
        return $this->bomId;
    }

    public function getEventName(): string
    {
        return 'mfg.bom.created';
    }

    public function toPayload(): array
    {
        return [
            'bom_id' => $this->bomId,
            'bom_number' => $this->bomNumber,
            'mfg_item_id' => $this->mfgItemId,
            'revision' => $this->revision,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
