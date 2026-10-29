<?php

declare(strict_types=1);

namespace Modules\HumanResources\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class PayrollForecastEvent implements DomainEventInterface
{
    public function __construct(
        public string $periodCode,
        public float $estimatedPayrollOutflow,
        public string $currency,
        public string $expectedPaymentDate,
        public int $activeHeadcount,
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
        return 'hr_payroll_forecast';
    }

    public function getAggregateId(): string
    {
        return $this->periodCode;
    }

    public function getEventName(): string
    {
        return 'treasury.forecast.payroll';
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
