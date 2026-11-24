<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Domain\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * Emitted when a sick leave or accident (INPS PUC, SUVA, INAIL, IJM/KTG, IPG/EO) is registered.
 * Sensitive protocol/claim details stay out of the public event bus payload unless required by payroll.
 */
final readonly class SickLeaveInpsRegisteredEvent implements DomainEventInterface
{
    /**
     * @param  array<string, mixed>  $indemnityBreakdown
     */
    public function __construct(
        public string $leaveRequestId,
        public string $employeeId,
        public string $country,
        public string $leaveType,
        public string $startDate,
        public string $endDate,
        public array $indemnityBreakdown,
        public bool $hasInpsProtocol,
        public bool $hasSuvaOrInailClaim,
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
        return 'lm_leave_request';
    }

    public function getAggregateId(): string
    {
        return $this->leaveRequestId;
    }

    public function getEventName(): string
    {
        return 'leave_management.sick_inps.registered';
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
