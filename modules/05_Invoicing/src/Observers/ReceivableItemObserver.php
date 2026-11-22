<?php

declare(strict_types=1);

namespace Modules\Invoicing\Observers;

use App\Models\Invoice;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Services\Receivables\ReceivableItemService;
use Throwable;

/**
 * Keeps the open items of a customer in line with its invoice whenever the invoice is saved with a new status, payment
 * status, paid total, due date or amount. It never breaks the save that triggered it: a failure is logged and rolled
 * back to a savepoint, and the next read of the receivables backfills what is missing.
 */
final class ReceivableItemObserver
{
    private const array WatchedColumns = ['status', 'payment_status', 'total_paid', 'total_gross', 'due_date', 'invoice_type'];

    public function __construct(private readonly ReceivableItemService $items) {}

    public function saved(Invoice|SalesInvoice $invoice): void
    {
        if (! Str::isUuid((string) $invoice->tenant_id) || ! ($invoice->wasRecentlyCreated || $invoice->wasChanged(self::WatchedColumns))) {
            return;
        }

        try {
            DB::transaction(function () use ($invoice): void {
                $sales = $invoice instanceof SalesInvoice ? $invoice : SalesInvoice::withoutGlobalScope(TenantScope::class)->find($invoice->id);

                if ($sales !== null) {
                    $this->items->syncInvoice($sales);
                }
            });
        } catch (Throwable $exception) {
            Log::error('The open items of an invoice could not be synchronised.', ['invoice_id' => $invoice->id, 'exception' => $exception->getMessage()]);
        }
    }
}
