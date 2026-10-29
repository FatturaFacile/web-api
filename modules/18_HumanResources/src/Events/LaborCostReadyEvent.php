<?php

declare(strict_types=1);

namespace Modules\HumanResources\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class LaborCostReadyEvent implements DomainEventInterface
{
    public function __construct(
        public string $timesheetId,
        public string $employeeId,
        public ?string $projectId,
        public ?string $costCenterId,
        public float $totalHours,
        public float $hourlyCostRate,
        public float $totalLaborCost,
        public string $workDate,
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
        return 'hr_timesheet';
    }

    public function getAggregateId(): string
    {
        return $this->timesheetId;
    }

    public function getEventName(): string
    {
        return 'accounting.hr.labor_cost_ready';
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
