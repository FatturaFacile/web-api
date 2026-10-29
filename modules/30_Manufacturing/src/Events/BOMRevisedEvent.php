<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

final class BOMRevisedEvent extends BaseManufacturingEvent
{
    public function __construct(
        public string $bomId,
        public string $bomNumber,
        public string $newRevision,
        public int $version,
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
        return 'mfg.bom.revised';
    }

    public function toPayload(): array
    {
        return [
            'bom_id' => $this->bomId,
            'bom_number' => $this->bomNumber,
            'new_revision' => $this->newRevision,
            'version' => $this->version,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
