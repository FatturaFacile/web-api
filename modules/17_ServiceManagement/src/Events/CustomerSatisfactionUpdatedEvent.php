<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class CustomerSatisfactionUpdatedEvent implements DomainEventInterface
{
    public function __construct(
        public string $csatSurveyId,
        public int $score,
        public ?string $feedback,
        public ?string $ticketId,
        public ?string $workOrderId,
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
        return 'esm_csat_survey';
    }

    public function getAggregateId(): string
    {
        return $this->csatSurveyId;
    }

    public function getEventName(): string
    {
        return 'esm.csat.updated';
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
        return get_object_vars($this);
    }
}
