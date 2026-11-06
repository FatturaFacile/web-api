<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documents that reach the ledger at most once. A manual or reversal entry is not bound by the index: only the
     * entry of the document itself, while it stands.
     */
    private const array SINGLE_ENTRY_SOURCES = ['sales_invoice', 'credit_note', 'vendor_invoice', 'vendor_credit_note', 'customer_payment', 'supplier_payment', 'treasury_payment_line', 'treasury_collection', 'payroll_run'];

    public function up(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->uuid('gl_journal_entry_id')->nullable()->index();
            $table->decimal('exchange_rate', 12, 6)->nullable();
        });

        Schema::table('payment_batches', function (Blueprint $table): void {
            $table->longText('pain001_xml')->nullable();
            $table->string('pain001_message_id', 50)->nullable()->index();
            $table->timestamp('exported_at')->nullable();
            $table->uuid('exported_by')->nullable();
        });

        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropForeign(['journal_entry_id']);
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->restrictOnDelete();
        });

        $sources = "'".implode("', '", self::SINGLE_ENTRY_SOURCES)."'";

        DB::statement("
            CREATE UNIQUE INDEX uq_journal_single_document_entry ON journal_entries (tenant_id, source_document_type, source_document_id)
            WHERE status = 'posted' AND journal_type <> 'reversing' AND source_document_type IN ({$sources}) AND deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_journal_single_document_entry');

        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropForeign(['journal_entry_id']);
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->cascadeOnDelete();
        });

        Schema::table('payment_batches', function (Blueprint $table): void {
            $table->dropColumn(['pain001_xml', 'pain001_message_id', 'exported_at', 'exported_by']);
        });

        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->dropColumn(['gl_journal_entry_id', 'exchange_rate']);
        });
    }
};
