<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            // AcctSvcrRef / NtryRef of the ISO 20022 entry, as the bank booked it.
            $table->string('entry_reference', 100)->nullable();
            // Identifies the booking across statements: the same movement in an overlapping file is stored once.
            $table->string('dedupe_key', 64)->nullable();

            $table->index(['tenant_id', 'dedupe_key']);
        });

        Schema::table('bank_statements', function (Blueprint $table): void {
            $table->unsignedInteger('duplicate_lines')->default(0);
        });

        Schema::table('payment_batches', function (Blueprint $table): void {
            // ISO 20022 version of the exported pain.001 file: 03 (pain.001.001.03) or 09 (pain.001.001.09).
            $table->string('pain_version', 2)->default('09');
        });
    }

    public function down(): void
    {
        Schema::table('payment_batches', function (Blueprint $table): void {
            $table->dropColumn('pain_version');
        });

        Schema::table('bank_statements', function (Blueprint $table): void {
            $table->dropColumn('duplicate_lines');
        });

        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'dedupe_key']);
            $table->dropColumn(['entry_reference', 'dedupe_key']);
        });
    }
};
