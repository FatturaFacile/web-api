<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keeps on the journal the real rate and treatment of each VAT line and the document number, so that returns and
     * registers can be derived from the posted entries. A settlement can be registered in the ledger only once.
     */
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->decimal('vat_rate_percent', 5, 2)->nullable();
            $table->string('vat_treatment', 30)->nullable();
        });

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->string('document_number', 100)->nullable();
        });

        DB::statement("CREATE UNIQUE INDEX uq_journal_entries_vat_settlement ON journal_entries (tenant_id, source_document_id) WHERE source_document_type = 'vat_settlement' AND journal_type <> 'reversing'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_journal_entries_vat_settlement');

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropColumn('document_number');
        });

        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropColumn(['vat_rate_percent', 'vat_treatment']);
        });
    }
};
