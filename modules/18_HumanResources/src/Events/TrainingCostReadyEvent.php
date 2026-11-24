<?php

declare(strict_types=1);

namespace Modules\HumanResources\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class TrainingCostReadyEvent implements DomainEventInterface
{
    public function __construct(
        public string $trainingRecordId,
        public string $employeeId,
        public string $courseId,
        public float $costAmount,
        public string $currency,
        public string $glExpenseAccountCode,
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
        return 'hr_training_record';
    }

    public function getAggregateId(): string
    {
        return $this->trainingRecordId;
    }

    public function getEventName(): string
    {
        return 'accounting.hr.training_cost_ready';
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
