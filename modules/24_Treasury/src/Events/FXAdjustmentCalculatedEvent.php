<?php

declare(strict_types=1);

namespace Modules\Treasury\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class FXAdjustmentCalculatedEvent implements DomainEventInterface
{
    public function __construct(
        public string $revaluationId,
        public string $currency,
        public float $unrealizedGainLoss,
        public string $periodEndDate,
        public ?string $journalEntryId,
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
        return 'fx_revaluation';
    }

    public function getAggregateId(): string
    {
        return $this->revaluationId;
    }

    public function getEventName(): string
    {
        return 'treasury.fx_adjustment.calculated';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function toPayload(): array
    {
        return get_object_vars($this);
    }
}
