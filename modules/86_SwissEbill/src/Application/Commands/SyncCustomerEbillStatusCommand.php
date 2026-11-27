<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Commands;

/**
 * Verifies the eBill enrollment of one customer against the participant directory and stores the answer on the customer.
 */
final readonly class SyncCustomerEbillStatusCommand
{
    public function __construct(public string $customerId, public bool $forceRefresh = false) {}
}
