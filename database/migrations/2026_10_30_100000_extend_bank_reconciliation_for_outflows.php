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
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->string('reconciliation_category', 30)->nullable()->index(); // invoice, tax_payment, payroll, bank_expense, internal_transfer, generic_expense
            $table->string('ledger_account_code', 20)->nullable();
            $table->text('reconciliation_notes')->nullable();
        });

        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->decimal('total_paid', 15, 2)->default(0);
        });

        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('passive_invoice_id')->constrained('passive_invoices')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('paid_at');
            $table->string('type', 20)->default('payment'); // payment, bank_fee, adjustment
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('bank_statement_line_id')->nullable()->index();
            $table->timestamps();

            $table->index(['passive_invoice_id', 'paid_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');

        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->dropColumn('total_paid');
        });

        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->dropColumn(['reconciliation_category', 'ledger_account_code', 'reconciliation_notes']);
        });
    }
};
