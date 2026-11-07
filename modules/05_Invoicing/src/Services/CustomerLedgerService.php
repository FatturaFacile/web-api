<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Invoicing\Contracts\CustomerLedgerServiceInterface;
use Modules\Invoicing\Models\CustomerLedgerEntry;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * The running balance of a customer. Each entry carries the balance after it, read from the previous entry: the entries
 * of one customer are written one at a time (a transaction-scoped advisory lock on tenant and customer), and the previous
 * entry is the last by creation time and then by id, so two concurrent postings never read the same previous balance.
 */
final class CustomerLedgerService implements CustomerLedgerServiceInterface
{
    public function recordInvoiceEntry(SalesInvoice $invoice, string $userId): CustomerLedgerEntry
    {
        return DB::transaction(fn (): CustomerLedgerEntry => $this->writeInvoiceEntry($invoice, $userId));
    }

    private function writeInvoiceEntry(SalesInvoice $invoice, string $userId): CustomerLedgerEntry
    {
        $tenantId = (string) $invoice->tenant_id;
        $customerId = (string) $invoice->contact_id;
        $gross = (float) $invoice->amountDue();

        $this->lockCustomer($tenantId, $customerId);
        $previousBalance = $this->previousBalance($tenantId, $customerId);

        $newBalance = round($previousBalance + $gross, 2);

        return CustomerLedgerEntry::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'invoice_id' => (string) $invoice->id,
            'entry_number' => 'CLE-INV-'.strtoupper(Str::random(8)),
            'entry_date' => $invoice->date ?? now()->toDateString(),
            'due_date' => $invoice->due_date ?? now()->addDays(30)->toDateString(),
            'entry_type' => 'invoice',
            'document_reference' => $invoice->invoice_number,
            'debit_amount' => $gross,
            'credit_amount' => 0.0,
            'balance_after' => $newBalance,
            'currency' => $invoice->currency ?? 'EUR',
            'exchange_rate' => (float) ($invoice->exchange_rate ?? 1.0),
            'status' => 'open',
            'cleared_amount' => 0.0,
            'gl_journal_entry_id' => $invoice->gl_journal_entry_id,
            'notes' => 'Customer invoice posted: '.$invoice->invoice_number,
            'created_by' => Str::isUuid($userId) ? $userId : null,
        ]);
    }

    public function recordCreditNoteEntry(SalesInvoice $creditNote, string $userId): CustomerLedgerEntry
    {
        return DB::transaction(fn (): CustomerLedgerEntry => $this->writeCreditNoteEntry($creditNote, $userId));
    }

    private function writeCreditNoteEntry(SalesInvoice $creditNote, string $userId): CustomerLedgerEntry
    {
        $tenantId = (string) $creditNote->tenant_id;
        $customerId = (string) $creditNote->contact_id;
        $gross = (float) $creditNote->amountDue();

        $this->lockCustomer($tenantId, $customerId);
        $previousBalance = $this->previousBalance($tenantId, $customerId);

        $newBalance = round($previousBalance - $gross, 2);

        return CustomerLedgerEntry::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'invoice_id' => (string) $creditNote->id,
            'entry_number' => 'CLE-CN-'.strtoupper(Str::random(8)),
            'entry_date' => $creditNote->date ?? now()->toDateString(),
            'due_date' => $creditNote->due_date ?? now()->toDateString(),
            'entry_type' => 'credit_note',
            'document_reference' => $creditNote->invoice_number,
            'debit_amount' => 0.0,
            'credit_amount' => $gross,
            'balance_after' => $newBalance,
            'currency' => $creditNote->currency ?? 'EUR',
            'exchange_rate' => (float) ($creditNote->exchange_rate ?? 1.0),
            'status' => 'cleared',
            'cleared_amount' => $gross,
            'cleared_at' => now(),
            'gl_journal_entry_id' => $creditNote->gl_journal_entry_id,
            'notes' => 'Credit note issued: '.$creditNote->invoice_number,
            'created_by' => Str::isUuid($userId) ? $userId : null,
        ]);
    }

    public function recordPaymentEntry(
        string $customerId,
        float $amount,
        string $paymentReference,
        string $tenantId,
        ?string $invoiceId = null
    ): CustomerLedgerEntry {
        return DB::transaction(fn (): CustomerLedgerEntry => $this->writePaymentEntry($customerId, $amount, $paymentReference, $tenantId, $invoiceId));
    }

    private function writePaymentEntry(string $customerId, float $amount, string $paymentReference, string $tenantId, ?string $invoiceId): CustomerLedgerEntry
    {
        $this->lockCustomer($tenantId, $customerId);
        $previousBalance = $this->previousBalance($tenantId, $customerId);

        $newBalance = round($previousBalance - $amount, 2);

        // If invoice specified, clear open entry
        if ($invoiceId !== null) {
            $openEntry = CustomerLedgerEntry::where('tenant_id', $tenantId)
                ->where('invoice_id', $invoiceId)
                ->where('entry_type', 'invoice')
                ->lockForUpdate()
                ->first();

            if ($openEntry !== null) {
                $cleared = min((float) $openEntry->debit_amount, (float) $openEntry->cleared_amount + $amount);
                $openEntry->update([
                    'cleared_amount' => $cleared,
                    'status' => $cleared >= (float) $openEntry->debit_amount ? 'cleared' : 'partially_cleared',
                    'cleared_at' => $cleared >= (float) $openEntry->debit_amount ? now() : null,
                ]);
            }
        }

        return CustomerLedgerEntry::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'invoice_id' => $invoiceId,
            'entry_number' => 'CLE-PAY-'.strtoupper(Str::random(8)),
            'entry_date' => now()->toDateString(),
            'due_date' => null,
            'entry_type' => 'payment',
            'document_reference' => $paymentReference,
            'debit_amount' => 0.0,
            'credit_amount' => $amount,
            'balance_after' => $newBalance,
            'currency' => 'EUR',
            'exchange_rate' => 1.0,
            'status' => 'cleared',
            'cleared_amount' => $amount,
            'cleared_at' => now(),
            'notes' => 'Customer payment received: '.$paymentReference,
        ]);
    }

    /**
     * Serializes the entries of one customer until the surrounding transaction ends. PostgreSQL only: other drivers
     * (SQLite in tests) already write one transaction at a time.
     */
    private function lockCustomer(string $tenantId, string $customerId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['customer_ledger:'.$tenantId.':'.$customerId]);
    }

    private function previousBalance(string $tenantId, string $customerId): float
    {
        return (float) (CustomerLedgerEntry::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->value('balance_after') ?? 0.0);
    }

    /**
     * @return array{
     *     current: float,
     *     days_1_30: float,
     *     days_31_60: float,
     *     days_61_90: float,
     *     days_90_plus: float,
     *     total_overdue: float,
     *     total_outstanding: float
     * }
     */
    public function calculateAging(string $customerId, string $tenantId): array
    {
        $openEntries = CustomerLedgerEntry::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->whereIn('status', ['open', 'partially_cleared'])
            ->where('entry_type', 'invoice')
            ->get();

        $current = 0.0;
        $days1To30 = 0.0;
        $days31To60 = 0.0;
        $days61To90 = 0.0;
        $days90Plus = 0.0;

        $today = Carbon::today();

        foreach ($openEntries as $entry) {
            $outstanding = (float) $entry->debit_amount - (float) $entry->cleared_amount;
            if ($outstanding <= 0.0) {
                continue;
            }

            $dueDate = $entry->due_date ? Carbon::parse($entry->due_date) : $today;

            if ($dueDate->gte($today)) {
                $current += $outstanding;
            } else {
                $daysOverdue = (int) $dueDate->diffInDays($today);

                if ($daysOverdue <= 30) {
                    $days1To30 += $outstanding;
                } elseif ($daysOverdue <= 60) {
                    $days31To60 += $outstanding;
                } elseif ($daysOverdue <= 90) {
                    $days61To90 += $outstanding;
                } else {
                    $days90Plus += $outstanding;
                }
            }
        }

        $totalOverdue = round($days1To30 + $days31To60 + $days61To90 + $days90Plus, 2);
        $totalOutstanding = round($current + $totalOverdue, 2);

        return [
            'current' => round($current, 2),
            'days_1_30' => round($days1To30, 2),
            'days_31_60' => round($days31To60, 2),
            'days_61_90' => round($days61To90, 2),
            'days_90_plus' => round($days90Plus, 2),
            'total_overdue' => $totalOverdue,
            'total_outstanding' => $totalOutstanding,
        ];
    }
}
