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

    public function generateRecurringInvoice(Subscription $subscription, Carbon $periodStart, Carbon $periodEnd, ?array $proration = null, string $invoiceType = 'regular'): array;

    /**
     * Final pro-rata settlement (invoice for unbilled days or credit for unused advance days) at contract end.
     *
     * @return array{type: string, amount: float, factor: float, service_days: int, cycle_days: int, invoice_id: string|null}|null
     */
    public function billFinalProRata(Subscription $subscription, Carbon $endDate): ?array;
}
