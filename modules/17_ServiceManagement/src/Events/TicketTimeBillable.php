<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Time logged on a ticket that no prepaid contract covers (time and materials): the billing side (Mod 080) turns it into a
 * billable line. The time entry id is the idempotency key, a consumer books each entry once.
 */
final readonly class TicketTimeBillable implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $tenantId,
        public string $ticketId,
        public string $timeEntryId,
        public string $customerId,
        public string $billableHours,
        public string $hourlyRate,
        public string $totalCharge,
    ) {}
}
