<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ProjectTimesheetApprovedEvent implements DomainEventInterface
{
    public function __construct(
        public string $timesheetId,
        public string $projectId,
        public string $userId,
        public float $hoursWorked,
        public float $totalCost,
        public float $totalCharge,
        public string $approverUserId,
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
        return 'project_timesheet';
    }

    public function getAggregateId(): string
    {
        return $this->timesheetId;
    }

    public function getEventName(): string
    {
        return 'project.timesheet_approved';
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
        return [
            'timesheet_id' => $this->timesheetId,
            'project_id' => $this->projectId,
            'user_id' => $this->userId,
            'hours_worked' => $this->hoursWorked,
            'total_cost' => $this->totalCost,
            'total_charge' => $this->totalCharge,
            'approver_user_id' => $this->approverUserId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
