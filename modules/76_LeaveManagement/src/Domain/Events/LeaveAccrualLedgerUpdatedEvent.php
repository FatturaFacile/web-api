<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Domain\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class LeaveAccrualLedgerUpdatedEvent implements DomainEventInterface
{
    public function __construct(
        public string $ledgerId,
        public string $employeeId,
        public int $fiscalYear,
        public string $leaveType,
        public string $accruedYtd,
        public string $projectedYearEndBalance,
        public string $tenantId,
        public string $eventId = '',
        public string $occurredAt = '',
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'lm_employee_leave_accrual_ledger';
    }

    public function getAggregateId(): string
    {
        return $this->ledgerId;
    }

    public function getEventName(): string
    {
        return 'leave_management.ledger.updated';
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
        return $this->occurredAt !== '' ? $this->occurredAt : now()->toIso8601String();
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return get_object_vars($this);
    }
}
