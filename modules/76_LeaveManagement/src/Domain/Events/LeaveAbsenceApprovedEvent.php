<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Domain\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * Published upon approval for ShiftPlanning (Mod 073), ResourcePlanning (Mod 079),
 * Timesheets (Mod 072), and CH/IT Payroll (Mod 076 / Mod 077).
 */
final readonly class LeaveAbsenceApprovedEvent implements DomainEventInterface
{
    public function __construct(
        public string $leaveRequestId,
        public string $requestNumber,
        public string $employeeId,
        public ?string $departmentId,
        public string $country,
        public string $leaveType,
        public string $startDate,
        public string $endDate,
        public float $requestedDays,
        public float $requestedHours,
        public ?string $approvedByUserId,
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
        return 'leave_management.request.approved';
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
