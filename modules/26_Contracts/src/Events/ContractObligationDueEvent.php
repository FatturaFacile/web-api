<?php

declare(strict_types=1);

namespace Modules\Contracts\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * An obligation or milestone of a contract entered its reminder window (or is already overdue) and nobody has finished
 * it. Notification channels subscribe to it; it is published once per item.
 */
final readonly class ContractObligationDueEvent implements DomainEventInterface
{
    /**
     * Create the event.
     */
    public function __construct(
        public string $obligationId,
        public string $contractKind,
        public string $contractId,
        public string $type,
        public string $title,
        public string $dueDate,
        public bool $isOverdue,
        public ?string $ownerUserId,
        public string $tenantId,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getEventId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * {@inheritDoc}
     */
    public function getEventName(): string
    {
        return 'contracts.obligation.due';
    }

    /**
     * {@inheritDoc}
     */
    public function getAggregateType(): string
    {
        return 'contract_obligation';
    }

    /**
     * {@inheritDoc}
     */
    public function getAggregateId(): string
    {
        return $this->obligationId;
    }

    /**
     * {@inheritDoc}
     */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * {@inheritDoc}
     */
    public function getCompanyId(): string
    {
        return $this->tenantId;
    }

    /**
     * {@inheritDoc}
     */
    public function toPayload(): array
    {
        return [
            'obligation_id' => $this->obligationId,
            'contract_kind' => $this->contractKind,
            'contract_id' => $this->contractId,
            'type' => $this->type,
            'title' => $this->title,
            'due_date' => $this->dueDate,
            'is_overdue' => $this->isOverdue,
            'owner_user_id' => $this->ownerUserId,
        ];
    }
}
