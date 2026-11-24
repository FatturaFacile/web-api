<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('source_format', 20); // cbi, camt053, camt054
            $table->string('account_iban', 34)->index();
            $table->date('statement_date');
            $table->decimal('opening_balance', 15, 2)->nullable();
            $table->decimal('closing_balance', 15, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->unsignedInteger('total_entries')->default(0);
            $table->string('status', 30)->default('draft'); // draft, partially_reconciled, reconciled
            $table->string('content_hash', 64)->nullable(); // rejects the same file imported twice
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'source_format']);
            $table->unique(['tenant_id', 'content_hash']);
        });

        Schema::create('bank_statement_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('bank_statement_id')->constrained('bank_statements')->cascadeOnDelete();
            $table->unsignedInteger('line_number')->default(0); // position in the source file
            $table->date('booking_date');
            $table->date('value_date');
            $table->decimal('amount', 15, 2); // net amount booked on the account
            $table->decimal('fee_amount', 15, 2)->default(0);
            $table->string('direction', 10); // credit, debit
            $table->string('abi_code', 10)->nullable();
            $table->string('reference')->nullable()->index(); // TRN / CRO / EndToEndId
            $table->string('qr_reference', 35)->nullable()->index(); // 27 digit QR reference or ISO 11649 reference
            $table->text('description')->nullable();
            $table->string('counterpart_name')->nullable();
            $table->string('counterpart_iban', 34)->nullable();
            $table->boolean('reconciled')->default(false);
            $table->uuid('matched_document_id')->nullable()->index(); // invoices.id
            $table->string('reconciliation_type', 50)->nullable(); // exact, qr_reference, fuzzy_tolerance, partial, manual
            $table->timestamps();

            $table->index(['tenant_id', 'reconciled']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('type', 20)->default('payment'); // payment, bank_fee, adjustment
            $table->uuid('bank_statement_line_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['type', 'bank_statement_line_id']);
        });
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statements');
    }
};
