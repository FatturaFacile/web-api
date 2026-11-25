<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Commands;

use Illuminate\Support\Carbon;
use Modules\IndustrialLaundry\Models\BillingRun;
use Modules\IndustrialLaundry\Models\ParContract;
use Modules\IndustrialLaundry\Services\MonthlyBillingService;

final class CalculateMonthlyLinenWashAndRentalInvoiceHandler
{
    public function __construct(private readonly MonthlyBillingService $billing) {}

    public function handle(CalculateMonthlyLinenWashAndRentalInvoiceCommand $command): BillingRun
    {
        $contract = ParContract::query()->with('lines')->findOrFail($command->contractId);

        return $this->billing->calculate($command->tenantId, $contract, Carbon::parse($command->periodStart), Carbon::parse($command->periodEnd), $command->createInvoice, $command->userId);
    }
}
