<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Commands;

final readonly class IssueBillingInvoiceCommand
{
    public function __construct(public string $billingRunId, public ?string $userId) {}
}
