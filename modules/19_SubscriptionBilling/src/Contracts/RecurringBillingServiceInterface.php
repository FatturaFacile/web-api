<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Contracts;

use Carbon\Carbon;
use Modules\SubscriptionBilling\DTOs\ExecuteBillingRunDTO;
use Modules\SubscriptionBilling\Models\BillingRun;
use Modules\SubscriptionBilling\Models\Subscription;

interface RecurringBillingServiceInterface
{
    public function executeBillingRun(ExecuteBillingRunDTO $dto): BillingRun;

    public function generateRecurringInvoice(Subscription $subscription, Carbon $periodStart, Carbon $periodEnd): array;
}
