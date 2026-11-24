<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\SubscriptionBilling\Contracts\RecurringBillingServiceInterface;
use Modules\SubscriptionBilling\DTOs\ExecuteBillingRunDTO;

final class ProcessRecurringBillingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $tenantId,
        public ?string $companyId = null,
        public ?string $runDate = null,
        public int $lookaheadDays = 0
    ) {}

    public function handle(RecurringBillingServiceInterface $billingService): void
    {
        $dto = new ExecuteBillingRunDTO(
            tenantId: $this->tenantId,
            companyId: $this->companyId,
            runDate: $this->runDate,
            lookaheadDays: $this->lookaheadDays,
            executedBy: null
        );

        $billingService->executeBillingRun($dto);
    }
}
