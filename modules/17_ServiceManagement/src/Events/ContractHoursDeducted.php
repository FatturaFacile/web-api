<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Events;

/**
 * Hours were booked against a maintenance contract hour bank (after commit of the ledger row).
 */
final readonly class ContractHoursDeducted
{
    public function __construct(
        public string $contractId,
        public string $transactionId,
        public string $tenantId,
    ) {}
}
