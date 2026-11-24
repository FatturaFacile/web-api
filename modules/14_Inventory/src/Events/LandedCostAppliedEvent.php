<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * Raised when a landed cost document (or its reversal document) is applied. The General Ledger
 * posts a balanced journal from `legs` with source_document_type `landed_cost`.
 *
 * Legs: role is one of inventory | cogs | ppv | landed_cost_clearing; account_id is an optional
 * category-level override; debit/credit are 2-decimal strings in company currency.
 */
final readonly class LandedCostAppliedEvent implements DomainEventInterface
{
    /**
     * @param  list<array{role: string, account_id: string|null, debit: string, credit: string, description: string}>  $legs
     */
    public function __construct(
        public string $landedCostDocumentId,
        public string $documentNumber,
        public string $revaluationId,
        public string $postingDate,
        public string $totalAmount,
        public array $legs,
        public bool $isReversal,
        public string $tenantId,
        public string $companyId = '',
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function sourceDocumentType(): string
    {
        return 'landed_cost';
    }

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'landed_cost_document';
    }

    public function getAggregateId(): string
    {
        return $this->landedCostDocumentId;
    }

    public function getEventName(): string
    {
        return 'inventory.landed_cost_applied';
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
            'landed_cost_document_id' => $this->landedCostDocumentId,
            'document_number' => $this->documentNumber,
            'revaluation_id' => $this->revaluationId,
            'posting_date' => $this->postingDate,
            'total_amount' => $this->totalAmount,
            'legs' => $this->legs,
            'is_reversal' => $this->isReversal,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
