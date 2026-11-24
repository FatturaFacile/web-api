<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Listeners;

use Illuminate\Support\Facades\DB;
use Modules\AnalyticalAccounting\Models\AnalyticalEntry;
use Modules\AnalyticalAccounting\Services\AnalyticalPostingService;
use Modules\GeneralLedger\Events\JournalPostedEvent;
use Throwable;

/**
 * Mirrors the result lines (revenue, cost of goods, expense) of a posted general ledger journal into the management ledger
 * when the derivation rules give them a center. Sales invoices are handled by AnalyticalInvoiceListener, so their
 * journals are skipped. Read-only on the statutory ledger, idempotent per journal, and never fatal for the posting.
 */
final class AnalyticalJournalListener
{
    private const array TypeBySource = ['credit_note' => 'CREDIT_NOTE', 'purchase_invoice' => 'PURCHASE_INVOICE', 'supplier_invoice' => 'PURCHASE_INVOICE'];

    private const array FallbackElement = ['revenue' => 'REVENUE', 'cogs' => 'VARIABLE_DIRECT', 'expense' => 'FIXED_DIRECT'];

    public function __construct(private readonly AnalyticalPostingService $posting) {}

    public function handle(JournalPostedEvent $event): void
    {
        try {
            $journal = DB::table('journal_entries')->where('tenant_id', $event->tenantId)->where('id', $event->journalEntryId)->first();
            if ($journal === null || ($journal->source_document_type ?? null) === 'sales_invoice') {
                return;
            }
            if (AnalyticalEntry::query()->withoutGlobalScopes()->where('tenant_id', $event->tenantId)->where('gl_journal_entry_id', $event->journalEntryId)->exists()) {
                return;
            }
            $rows = DB::table('journal_lines as l')->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
                ->where('l.tenant_id', $event->tenantId)->where('l.journal_entry_id', $event->journalEntryId)->whereNull('l.deleted_at')
                ->whereIn('a.account_type', array_keys(self::FallbackElement))
                ->get(['l.id', 'l.account_id', 'l.debit_amount', 'l.credit_amount', 'l.description', 'a.account_type']);

            $lines = [];
            foreach ($rows as $row) {
                $signed = $row->account_type === 'revenue' ? bcsub((string) $row->credit_amount, (string) $row->debit_amount, 4) : bcsub((string) $row->debit_amount, (string) $row->credit_amount, 4);
                if (bccomp($signed, '0', 4) === 0) {
                    continue;
                }
                $derived = $this->posting->testDerivation($event->tenantId, ['account_id' => (string) $row->account_id, 'journal_type' => $event->journalType, 'date' => $event->postingDate]);
                if ($derived['dimensions']['cost_center_id'] === null && $derived['dimensions']['profit_center_id'] === null) {
                    continue;
                }
                $lines[] = ['amount' => $signed, 'account_id' => (string) $row->account_id, 'journal_type' => $event->journalType, 'gl_journal_line_id' => (string) $row->id,
                    'fallback_cost_element_type' => self::FallbackElement[$row->account_type], 'description' => $row->description];
            }
            if ($lines === []) {
                return;
            }
            $source = (string) ($journal->source_document_type ?? '');
            $this->posting->post($event->tenantId, [
                'entry_type' => self::TypeBySource[$source] ?? 'GL_ALLOCATION', 'posting_date' => $event->postingDate,
                'description' => 'Journal '.$event->entryNumber, 'source_type' => $source !== '' ? $source : 'journal_entry',
                'source_id' => $journal->source_document_id ?? $event->journalEntryId, 'gl_journal_entry_id' => $event->journalEntryId,
            ], $lines);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
