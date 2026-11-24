<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Domain\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class LeaveAbsenceSubmittedEvent implements DomainEventInterface
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
        public bool $staffingThresholdBreached,
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
        return 'leave_management.request.submitted';
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
