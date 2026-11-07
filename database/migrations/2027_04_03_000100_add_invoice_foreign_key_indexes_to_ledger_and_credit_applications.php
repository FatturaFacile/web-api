<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes on the invoice foreign keys that PostgreSQL does not create by itself: the customer ledger entries of an
     * invoice and the credit applications settling an invoice are read on every payment and credit note. Plain
     * (non-unique) indexes, created only when missing, so the migration is safe on existing data.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS customer_ledger_entries_invoice_id_index ON customer_ledger_entries (invoice_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS invoice_credit_applications_original_invoice_id_index ON invoice_credit_applications (original_invoice_id)');

            return;
        }

        if (! Schema::hasIndex('customer_ledger_entries', ['invoice_id'])) {
            Schema::table('customer_ledger_entries', function (Blueprint $table): void {
                $table->index('invoice_id');
            });
        }

        if (! Schema::hasIndex('invoice_credit_applications', ['original_invoice_id'])) {
            Schema::table('invoice_credit_applications', function (Blueprint $table): void {
                $table->index('original_invoice_id');
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS customer_ledger_entries_invoice_id_index');
            DB::statement('DROP INDEX IF EXISTS invoice_credit_applications_original_invoice_id_index');

            return;
        }

        Schema::table('customer_ledger_entries', function (Blueprint $table): void {
            $table->dropIndex(['invoice_id']);
        });

        Schema::table('invoice_credit_applications', function (Blueprint $table): void {
            $table->dropIndex(['original_invoice_id']);
        });
    }
};
