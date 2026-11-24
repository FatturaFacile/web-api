<?php

declare(strict_types=1);

namespace Modules\Payroll\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * A closed run was reopened: whatever was derived from it, such as accounting entries, has to be reversed.
 */
final class PayrollRunReopenedEvent implements DomainEventInterface
{
    public readonly string $eventId;

    public readonly string $occurredAt;

    public function __construct(
        public readonly string $payrollRunId,
        public readonly int $year,
        public readonly int $month,
        public readonly string $reason,
        public readonly string $tenantId,
    ) {
        $this->eventId = (string) Str::uuid7();
        $this->occurredAt = now()->toIso8601String();
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getAggregateType(): string
    {
        return 'payroll_run';
    }

    public function getAggregateId(): string
    {
        return $this->payrollRunId;
    }

    public function getEventName(): string
    {
        return 'accounting.payroll.run_reopened';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->tenantId;
    }

    public function getOccurredAt(): string
    {
        return $this->occurredAt;
    }

    public function toPayload(): array
    {
        return get_object_vars($this);
    }
}
