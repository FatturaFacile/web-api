<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Listeners;

use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\SwissEbill\Infrastructure\Jobs\AutoTransmitInvoiceJob;

final class AutoTransmitOnInvoicePosted
{
    public function handle(InvoicePostedEvent $event): void
    {
        AutoTransmitInvoiceJob::dispatch($event->invoiceId, $event->tenantId)->afterCommit();
    }
}
