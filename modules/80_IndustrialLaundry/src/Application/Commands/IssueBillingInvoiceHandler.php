<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Commands;

use Modules\IndustrialLaundry\Models\BillingRun;
use Modules\IndustrialLaundry\Services\MonthlyBillingService;

final class IssueBillingInvoiceHandler
{
    public function __construct(private readonly MonthlyBillingService $billing) {}

    public function handle(IssueBillingInvoiceCommand $command): BillingRun
    {
        return $this->billing->issueInvoice(BillingRun::query()->findOrFail($command->billingRunId), $command->userId);
    }
}
