<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One sale document per POS sale, and transmissions that happen after the commit with an idempotency key.
 *
 * A POS sale that already got more than one sale document (two concurrent requests before this index existed) keeps the
 * first one, by fiscal year and number. The later ones stay: they are numbered fiscal documents and cannot be deleted,
 * but they lose the link to the sale, so the unique index can be built.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_documents', function (Blueprint $table): void {
            $table->uuid('transmission_key')->nullable();
            $table->string('gateway_error', 500)->nullable();
        });

        Schema::table('daily_corrispettivi', function (Blueprint $table): void {
            $table->uuid('transmission_key')->nullable();
            $table->string('gateway_error', 500)->nullable();
        });

        Schema::table('rt_fiscal_receipts', function (Blueprint $table): void {
            $table->string('transmission_error', 500)->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE commercial_documents SET pos_sale_id = NULL
            WHERE id IN (
                SELECT id FROM (
                    SELECT id, ROW_NUMBER() OVER (PARTITION BY pos_sale_id ORDER BY fiscal_year, sequential_number, id) AS position
                    FROM commercial_documents
                    WHERE document_type = 'sale' AND pos_sale_id IS NOT NULL
                ) AS numbered
                WHERE position > 1
            )
            SQL);

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS commercial_documents_pos_sale_unique ON commercial_documents (pos_sale_id) WHERE document_type = 'sale' AND pos_sale_id IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS commercial_documents_pos_sale_unique');

        Schema::table('rt_fiscal_receipts', function (Blueprint $table): void {
            $table->dropColumn('transmission_error');
        });

        Schema::table('daily_corrispettivi', function (Blueprint $table): void {
            $table->dropColumn(['transmission_key', 'gateway_error']);
        });

        Schema::table('commercial_documents', function (Blueprint $table): void {
            $table->dropColumn(['transmission_key', 'gateway_error']);
        });
    }
};
