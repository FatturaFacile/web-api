<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The special regime of Law 398/1991: a flag per company, and the simplified register of the commercial income on
     * which the ceiling of the year is monitored. The ceiling and the rates are legal parameters of the year. Entries
     * are never deleted: a correction is a further entry, so the register keeps its history.
     */
    public function up(): void
    {
        Schema::table('assoc_settings', function (Blueprint $table): void {
            $table->boolean('lex398_enabled')->default(false);
            $table->date('lex398_since')->nullable();
        });

        Schema::create('assoc_lex398_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->date('entry_date');
            $table->string('document_number', 60);
            $table->string('counterparty')->nullable();
            $table->string('description', 500)->nullable();
            $table->decimal('taxable_amount', 14, 2);
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->string('source_type', 20)->default('manual');
            $table->uuid('source_id')->nullable();
            $table->uuid('recorded_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'entry_date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX assoc_lex398_entries_source_unique ON assoc_lex398_entries (tenant_id, source_type, source_id) WHERE source_id IS NOT NULL');

            DB::unprepared("
                CREATE OR REPLACE FUNCTION prevent_assoc_lex398_entry_mutation()
                RETURNS TRIGGER AS $$
                BEGIN
                    RAISE EXCEPTION 'Entries of the Law 398/1991 register cannot be changed or deleted: record a correcting entry.';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER trg_prevent_assoc_lex398_entry_mutation BEFORE UPDATE OR DELETE ON assoc_lex398_entries
                FOR EACH ROW EXECUTE FUNCTION prevent_assoc_lex398_entry_mutation();
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('
                DROP TRIGGER IF EXISTS trg_prevent_assoc_lex398_entry_mutation ON assoc_lex398_entries;
                DROP FUNCTION IF EXISTS prevent_assoc_lex398_entry_mutation();
            ');
        }

        Schema::dropIfExists('assoc_lex398_entries');

        Schema::table('assoc_settings', function (Blueprint $table): void {
            $table->dropColumn(['lex398_enabled', 'lex398_since']);
        });
    }
};
