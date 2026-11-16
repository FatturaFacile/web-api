<?php

declare(strict_types=1);

namespace Modules\HumanResources\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * Swiss payroll run closed. Carries the run totals (integer Rappen) so General Ledger and Treasury can post
 * salary costs, social insurance liabilities and the net salary payment later.
 */
final readonly class PayrollRunClosedEvent implements DomainEventInterface
{
    public function __construct(
        public string $payrollRunId,
        public string $runNumber,
        public string $periodCode,
        public ?string $paymentDate,
        public string $currency,
        public int $employeeCount,
        public int $grossRappen,
        public int $employeeDeductionsRappen,
        public int $employerContributionsRappen,
        public int $withholdingTaxRappen,
        public int $netRappen,
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
        return 'hr_payroll_run';
    }

    public function getAggregateId(): string
    {
        return $this->payrollRunId;
    }

    public function getEventName(): string
    {
        return 'hr.payroll.run.closed';
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
