<?php

declare(strict_types=1);

namespace Modules\Treasury\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class CashForecastGeneratedEvent implements DomainEventInterface
{
    public function __construct(
        public string $cashForecastId,
        public string $calculationDate,
        public int $horizonDays,
        public float $openingBalance,
        public float $projectedInflows,
        public float $projectedOutflows,
        public float $closingBalance,
        public bool $deficitDetected,
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
        return 'cash_forecast';
    }

    public function getAggregateId(): string
    {
        return $this->cashForecastId;
    }

    public function getEventName(): string
    {
        return 'treasury.cash_forecast.generated';
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
