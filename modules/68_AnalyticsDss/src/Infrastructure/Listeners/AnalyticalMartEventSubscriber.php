<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Infrastructure\Listeners;

use App\Events\Inventory\StockMovementRecorded;
use Illuminate\Events\Dispatcher;
use Modules\AnalyticsDss\Infrastructure\Jobs\UpdateAnalyticalMartJob;
use Modules\GeneralLedger\Events\JournalPostedEvent;
use Modules\Invoicing\Events\InvoicePaidEvent;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Projects\Events\ProjectTimesheetApprovedEvent;
use Modules\Treasury\Events\CollectionReceivedEvent;

/**
 * Turns the operational events into queued mart updates: ledger postings, invoices, settled payments, stock movements and
 * approved timesheets. The listeners only enqueue; the projection runs on the `analytics` queue.
 */
final class AnalyticalMartEventSubscriber
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(JournalPostedEvent::class, fn (JournalPostedEvent $e) => $this->enqueue($e->tenantId, UpdateAnalyticalMartJob::SourceJournalEntry, $e->journalEntryId));
        $events->listen(InvoicePostedEvent::class, fn (InvoicePostedEvent $e) => $this->enqueue($e->tenantId, UpdateAnalyticalMartJob::SourceInvoice, $e->invoiceId));
        $events->listen(InvoicePaidEvent::class, fn (InvoicePaidEvent $e) => $this->enqueue($e->tenantId, UpdateAnalyticalMartJob::SourceInvoice, $e->invoiceId));
        $events->listen(CollectionReceivedEvent::class, function (CollectionReceivedEvent $e): void {
            if ($e->invoiceId !== null) {
                $this->enqueue($e->tenantId, UpdateAnalyticalMartJob::SourceInvoice, $e->invoiceId);
            }
        });
        $events->listen(ProjectTimesheetApprovedEvent::class, fn (ProjectTimesheetApprovedEvent $e) => $this->enqueue($e->tenantId, UpdateAnalyticalMartJob::SourceTimesheet, $e->timesheetId));
        $events->listen(StockMovementRecorded::class, fn (StockMovementRecorded $e) => $this->enqueue((string) $e->movement->tenant_id, UpdateAnalyticalMartJob::SourceStockMovement, (string) $e->movement->getKey()));
    }

    private function enqueue(string $tenantId, string $source, string $sourceId): void
    {
        if ($tenantId === '' || $sourceId === '') {
            return;
        }

        UpdateAnalyticalMartJob::dispatch($tenantId, $source, $sourceId);
    }
}
