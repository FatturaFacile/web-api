<?php

declare(strict_types=1);

namespace Modules\Treasury\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * A supplier payment proposal was approved by someone other than its author and became an approved payment batch.
 */
final readonly class PaymentProposalApprovedEvent implements DomainEventInterface
{
    public function __construct(
        public string $proposalId,
        public string $paymentBatchId,
        public string $approvedBy,
        public string $totalAmount,
        public string $currency,
        public int $itemCount,
        public string $tenantId,
        public string $companyId,
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'treasury_payment_proposal';
    }

    public function getAggregateId(): string
    {
        return $this->proposalId;
    }

    public function getEventName(): string
    {
        return 'treasury.payment_proposal.approved';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return get_object_vars($this);
    }
}
