<?php

declare(strict_types=1);

namespace Modules\Treasury\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class BankStatementImportedEvent implements DomainEventInterface
{
    public function __construct(
        public string $statementId,
        public string $bankAccountId,
        public string $statementNumber,
        public string $statementDate,
        public float $closingBalance,
        public int $linesCount,
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
        return 'bank_statement';
    }

    public function getAggregateId(): string
    {
        return $this->statementId;
    }

    public function getEventName(): string
    {
        return 'treasury.bank_statement.imported';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function toPayload(): array
    {
        return get_object_vars($this);
    }
}
