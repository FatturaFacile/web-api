<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;

final readonly class InFieldDocumentIssuedEvent implements DomainEventInterface
{
    public function __construct(
        private string $visitOutcomeId,
        private string $tenantId,
        private string $vanWarehouseId,
        private string $contactId,
        private string $documentType,
        private string $definitiveDocumentNumber,
        private float $totalGross,
    ) {}

    public function getEventName(): string
    {
        return 'van_sales.in_field_document_issued';
    }

    public function getAggregateType(): string
    {
        return 'van_visit_outcome';
    }

    public function getAggregateId(): string
    {
        return $this->visitOutcomeId;
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->tenantId;
    }

    public function toPayload(): array
    {
        return [
            'visit_outcome_id' => $this->visitOutcomeId,
            'van_warehouse_id' => $this->vanWarehouseId,
            'contact_id' => $this->contactId,
            'document_type' => $this->documentType,
            'definitive_document_number' => $this->definitiveDocumentNumber,
            'total_gross' => $this->totalGross,
        ];
    }
}
