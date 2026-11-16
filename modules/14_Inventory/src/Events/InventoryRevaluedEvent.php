<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * Raised when inventory is revalued outside a landed cost (valuation method change, standard
 * cost change, manual). The General Ledger posts the inventory vs COGS adjustment from `legs`
 * with source_document_type `inventory_revaluation`.
 */
final readonly class InventoryRevaluedEvent implements DomainEventInterface
{
    /**
     * @param  list<array{role: string, account_id: string|null, debit: string, credit: string, description: string}>  $legs
     */
    public function __construct(
        public string $revaluationId,
        public string $sourceType,
        public string $postingDate,
        public string $totalInventoryDelta,
        public string $totalCogsDelta,
        public array $legs,
        public string $tenantId,
        public string $companyId = '',
        public string $description = 'Inventory revaluation',
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function sourceDocumentType(): string
    {
        return 'inventory_revaluation';
    }

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'inventory_revaluation';
    }

    public function getAggregateId(): string
    {
        return $this->revaluationId;
    }

    public function getEventName(): string
    {
        return 'inventory.revalued';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getOccurredAt(): string
    {
        return $this->occurredAt !== '' ? $this->occurredAt : now()->toIso8601String();
    }

    public function toPayload(): array
    {
        return [
            'revaluation_id' => $this->revaluationId,
            'source_type' => $this->sourceType,
            'posting_date' => $this->postingDate,
            'total_inventory_delta' => $this->totalInventoryDelta,
            'total_cogs_delta' => $this->totalCogsDelta,
            'legs' => $this->legs,
            'description' => $this->description,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
