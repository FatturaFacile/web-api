<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Listeners;

use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;

/**
 * A subscription invoice left as a draft (subscription_billing.auto_post_invoices = false) and issued later from the
 * invoicing module: its billing period, still `processing`, becomes `invoiced`. Idempotent.
 */
final readonly class SalesInvoiceSubscriptionListener
{
    public function handle(InvoicePostedEvent $event): void
    {
        InvoiceSchedule::withoutGlobalScopes()
            ->where('tenant_id', $event->tenantId)
            ->where('invoice_id', $event->invoiceId)
            ->where('status', 'processing')
            ->update(['status' => 'invoiced', 'estimated_amount' => number_format($event->totalGross, 2, '.', ''), 'updated_at' => now()]);
    }
}
