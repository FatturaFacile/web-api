<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Observers;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\AgentCommissions\Services\CommissionAccrualService;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Treasury\Models\BankStatementLine;
use Throwable;

/**
 * Keeps the commission ledger in step with the sales flow, whichever code path changes it: an invoice that is issued,
 * voided or credited, a payment recorded / changed / removed, a bank statement line reconciled or un-reconciled. The
 * sync is idempotent. It never breaks the save that triggered it: a failure is logged and rolled back to a savepoint.
 */
final class CommissionSyncObserver
{
    private const array WatchedInvoiceFields = ['status', 'invoice_number', 'invoice_type', 'total_gross', 'total_net', 'related_invoice_id', 'sales_rep_id'];

    public function __construct(private readonly CommissionAccrualService $accruals) {}

    public function saved(Invoice|SalesInvoice $invoice): void
    {
        if ($invoice->wasRecentlyCreated || $invoice->wasChanged(self::WatchedInvoiceFields)) {
            $this->sync([(string) $invoice->id]);
        }
    }

    public function paymentSaved(Payment $payment): void
    {
        $this->sync([(string) $payment->invoice_id]);
    }

    public function paymentDeleted(Payment $payment): void
    {
        $this->sync([(string) $payment->invoice_id]);
    }

    public function lineUpdated(BankStatementLine $line): void
    {
        if (! $line->wasChanged('reconciled')) {
            return;
        }

        $this->sync(DB::table('payments')->where('bank_statement_line_id', $line->id)->whereNull('deleted_at')->pluck('invoice_id')->map(static fn (mixed $id): string => (string) $id)->unique()->all());
    }

    /**
     * @param  list<string>  $invoiceIds
     */
    private function sync(array $invoiceIds): void
    {
        foreach ($invoiceIds as $invoiceId) {
            try {
                DB::transaction(fn () => $this->accruals->syncInvoice($invoiceId));
            } catch (Throwable $exception) {
                Log::error('The commission ledger could not be synchronised.', ['invoice_id' => $invoiceId, 'exception' => $exception->getMessage()]);
            }
        }
    }
}
