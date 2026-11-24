<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Actions;

use Modules\SubscriptionBilling\Contracts\RecurringBillingServiceInterface;
use Modules\SubscriptionBilling\DTOs\ExecuteBillingRunDTO;
use Modules\SubscriptionBilling\Models\BillingRun;

final readonly class ExecuteBillingRunAction
{
    public function __construct(
        private RecurringBillingServiceInterface $billingService
    ) {}

    public function execute(ExecuteBillingRunDTO $dto): BillingRun
    {
        return $this->billingService->executeBillingRun($dto);
    }
}
