<?php

declare(strict_types=1);

namespace Modules\Procurement\Listeners;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Procurement\Events\InvoiceMatchBlockedEvent;
use Modules\Procurement\Notifications\InvoiceMatchBlockedNotification;
use Throwable;

/**
 * Notifies the purchasing and accounts payable team (owners, administrators, accountants and purchasers of the company)
 * when an invoice is blocked for discrepancy. A failed notification never undoes the block.
 */
final class NotifyPurchasingOfBlockedInvoiceListener
{
    /** Roles of the company members that work the blocked invoice queue. */
    private const array Roles = ['owner', 'admin', 'accountant', 'purchasing', 'procurement_manager', 'cfo'];

    public function handle(InvoiceMatchBlockedEvent $event): void
    {
        try {
            $tenant = Tenant::query()->find($event->tenantId);

            if ($tenant === null) {
                return;
            }

            $recipients = $tenant->users()->wherePivotIn('role', self::Roles)->get();

            if ($recipients->isEmpty()) {
                return;
            }

            Notification::send($recipients, new InvoiceMatchBlockedNotification(
                runId: $event->runId,
                passiveInvoiceId: $event->passiveInvoiceId,
                invoiceNumber: $event->invoiceNumber,
                varianceAmount: $event->varianceAmount,
                currency: $event->currency,
                reasons: $event->reasons,
            ));
        } catch (Throwable $exception) {
            Log::warning('Blocked invoice not notified.', ['run_id' => $event->runId, 'reason' => $exception->getMessage()]);
        }
    }
}
