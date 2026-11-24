<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Listeners;

use Illuminate\Support\Facades\DB;
use Modules\AnalyticalAccounting\Models\AnalyticalEntry;
use Modules\AnalyticalAccounting\Services\AnalyticalPostingService;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Throwable;

/**
 * Splits the revenue lines of a posted sales invoice into the management ledger (SALES_INVOICE). It is idempotent per
 * invoice, never touches the statutory ledger, and a failure here is reported but never stops the invoice posting.
 */
final class AnalyticalInvoiceListener
{
    public function __construct(private readonly AnalyticalPostingService $posting) {}

    public function handle(InvoicePostedEvent $event): void
    {
        try {
            $exists = AnalyticalEntry::query()->withoutGlobalScopes()->where('tenant_id', $event->tenantId)
                ->where('source_type', 'sales_invoice')->where('source_id', $event->invoiceId)->exists();
            if ($exists) {
                return;
            }
            $centerIds = DB::table('analytical_centers')->where('tenant_id', $event->tenantId)->whereNull('deleted_at')->pluck('id')->all();
            $lines = [];
            foreach ($event->revenueLines as $line) {
                $explicit = [];
                foreach (['cost_center_id', 'profit_center_id'] as $dimension) {
                    if (in_array($line[$dimension] ?? null, $centerIds, true)) {
                        $explicit[$dimension] = $line[$dimension];
                    }
                }
                $candidate = ['amount' => (string) $line['amount'], 'cost_element_type' => 'REVENUE', 'account_id' => $line['account_id'] ?? null, 'invoice_line_id' => $line['line_id'] ?? null, 'description' => $line['description'] ?? null] + $explicit;
                $derived = $this->posting->testDerivation($event->tenantId, ['account_id' => $line['account_id'] ?? null, 'date' => $event->date], $explicit + ['cost_element_type' => 'REVENUE']);
                if ($derived['dimensions']['cost_center_id'] === null && $derived['dimensions']['profit_center_id'] === null) {
                    continue;
                }
                $lines[] = $candidate;
            }
            if ($lines === []) {
                return;
            }
            $this->posting->post($event->tenantId, [
                'entry_type' => 'SALES_INVOICE', 'posting_date' => $event->date, 'currency' => $event->currency,
                'description' => 'Invoice '.$event->invoiceNumber, 'source_type' => 'sales_invoice', 'source_id' => $event->invoiceId,
                'gl_journal_entry_id' => $event->glJournalEntryId, 'invoice_id' => $event->invoiceId,
            ], $lines);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
