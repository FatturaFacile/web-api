<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A commission statement reaches the ledger at most once, the way the documents of uq_journal_single_document_entry
     * do, and an invoice accrues its commission once. Duplicates already in the data stop the migration with the list,
     * because choosing which one to keep is an accounting decision.
     */
    public function up(): void
    {
        $postedTwice = DB::select("
            SELECT tenant_id, source_document_id FROM journal_entries
            WHERE status = 'posted' AND journal_type <> 'reversing' AND source_document_type = 'sales_commission_statement' AND deleted_at IS NULL
            GROUP BY tenant_id, source_document_id HAVING COUNT(*) > 1
        ");

        if ($postedTwice !== []) {
            throw new RuntimeException('Commission statements posted more than once, reverse the extra entries first: '.implode(', ', array_map(static fn (object $row): string => "{$row->tenant_id}/{$row->source_document_id}", $postedTwice)));
        }

        $accruedTwice = DB::select('
            SELECT tenant_id, invoice_id FROM sales_commissions
            WHERE invoice_id IS NOT NULL AND deleted_at IS NULL
            GROUP BY tenant_id, invoice_id HAVING COUNT(*) > 1
        ');

        if ($accruedTwice !== []) {
            throw new RuntimeException('Invoices with more than one commission, delete the extra ones first: '.implode(', ', array_map(static fn (object $row): string => "{$row->tenant_id}/{$row->invoice_id}", $accruedTwice)));
        }

        DB::statement("
            CREATE UNIQUE INDEX uq_journal_sales_commission_statement_entry ON journal_entries (tenant_id, source_document_id)
            WHERE status = 'posted' AND journal_type <> 'reversing' AND source_document_type = 'sales_commission_statement' AND deleted_at IS NULL
        ");

        DB::statement('CREATE UNIQUE INDEX uq_sales_commissions_invoice ON sales_commissions (tenant_id, invoice_id) WHERE invoice_id IS NOT NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_sales_commissions_invoice');
        DB::statement('DROP INDEX IF EXISTS uq_journal_sales_commission_statement_entry');
    }
};
