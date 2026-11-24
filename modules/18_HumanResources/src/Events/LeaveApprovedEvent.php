<?php

declare(strict_types=1);

namespace Modules\HumanResources\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class LeaveApprovedEvent implements DomainEventInterface
{
    public function __construct(
        public string $leaveRequestId,
        public string $requestNumber,
        public string $employeeId,
        public string $leaveTypeId,
        public float $totalHours,
        public ?string $approvedByUserId,
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
        return 'hr_leave_request';
    }

    public function getAggregateId(): string
    {
        return $this->leaveRequestId;
    }

    public function getEventName(): string
    {
        return 'hr.leave.approved';
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
