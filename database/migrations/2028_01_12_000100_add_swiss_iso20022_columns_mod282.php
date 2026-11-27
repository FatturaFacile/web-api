<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #282 — Swiss ISO 20022: pain.001 category purpose / charge bearer per batch, the QR-bill identifiers and
 * reconciliation thresholds of the company bank account, and the exception code of a statement line the automatic
 * reconciliation could not settle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_batches', function (Blueprint $table): void {
            $table->string('category_purpose', 4)->nullable();
            $table->string('charge_bearer', 4)->nullable();
        });

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->string('qr_iid', 5)->nullable();
            $table->string('besr_id', 9)->nullable();
            $table->string('preferred_pain_version', 2)->nullable();
            $table->decimal('reconciliation_tolerance', 10, 2)->nullable();
            $table->decimal('reconciliation_max_fee', 10, 2)->nullable();
        });

        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->string('exception_code', 30)->nullable();
            $table->uuid('exception_candidate_id')->nullable();
            $table->index(['tenant_id', 'exception_code'], 'bank_statement_lines_exception_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->dropIndex('bank_statement_lines_exception_idx');
            $table->dropColumn(['exception_code', 'exception_candidate_id']);
        });

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropColumn(['qr_iid', 'besr_id', 'preferred_pain_version', 'reconciliation_tolerance', 'reconciliation_max_fee']);
        });

        Schema::table('payment_batches', function (Blueprint $table): void {
            $table->dropColumn(['category_purpose', 'charge_bearer']);
        });
    }
};
