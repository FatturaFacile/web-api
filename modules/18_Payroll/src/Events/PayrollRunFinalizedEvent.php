<?php

declare(strict_types=1);

namespace Modules\Payroll\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

/**
 * A run was closed. It carries what accounting needs to post the payroll: the desktop does not post it, and the chart
 * of accounts has no payroll mapping yet, so General Ledger subscribes to this event when it has one.
 *
 * Amounts are decimal strings.
 */
final class PayrollRunFinalizedEvent implements DomainEventInterface
{
    public readonly string $eventId;

    public readonly string $occurredAt;

    /**
     * @param  array<string, string>  $totals  gross, deductions, net, payout and employer contributions
     * @param  array<string, string>  $amountsByCode  the total of the lines of each wage type
     */
    public function __construct(
        public readonly string $payrollRunId,
        public readonly int $year,
        public readonly int $month,
        public readonly ?string $paymentDate,
        public readonly string $currency,
        public readonly array $totals,
        public readonly array $amountsByCode,
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
        return 'accounting.payroll.run_finalized';
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
