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
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('document_type', 30); // invoice, credit_note, proforma, quote, sales_order, delivery_note
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('prefix', 20)->default('');
            $table->unsignedInteger('last_number')->default(0);
            $table->unsignedTinyInteger('padding')->default(4);
            $table->timestamps();

            $table->unique(['tenant_id', 'document_type', 'fiscal_year']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('paid_at');
            $table->string('payment_method', 4)->nullable(); // MP01-MP23
            $table->string('reference', 100)->nullable(); // CRO/TRN, cheque number...
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'paid_at']);
        });

        Schema::create('document_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuidMorphs('eventable');
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('event', 50)->index();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedSmallInteger('fiscal_year')->nullable()->after('invoice_number');
            $table->unsignedInteger('sequential_number')->nullable()->after('fiscal_year');
            $table->decimal('total_paid', 15, 2)->default(0)->after('rounding_amount');
            $table->foreignUuid('proforma_id')->nullable()->after('contact_id')->constrained('invoices')->nullOnDelete();
            $table->foreignUuid('sales_order_id')->nullable()->after('proforma_id')->constrained('sales_orders')->nullOnDelete();
            $table->foreignUuid('related_invoice_id')->nullable()->after('sales_order_id')->constrained('invoices')->nullOnDelete();
            $table->string('payment_method', 4)->nullable(); // MP01-MP23
            $table->string('payment_terms', 4)->nullable(); // TP01-TP03
            $table->string('bank_name')->nullable();
            $table->string('bank_iban', 34)->nullable();
            $table->string('vat_payability', 1)->default('I'); // I, D, S
            $table->boolean('withholding_tax_enabled')->default(false);
            $table->decimal('withholding_tax_percent', 5, 2)->nullable();
            $table->decimal('withholding_tax_amount', 15, 2)->nullable();
            $table->boolean('stamp_duty_applied')->default(false);
            $table->decimal('stamp_duty_amount', 15, 2)->nullable();
            $table->boolean('fund_enabled')->default(false);
            $table->string('fund_type', 4)->nullable(); // TC01-TC22
            $table->decimal('fund_percent', 5, 2)->nullable();
            $table->decimal('fund_amount', 15, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('proforma_id');
            $table->dropConstrainedForeignId('sales_order_id');
            $table->dropConstrainedForeignId('related_invoice_id');
            $table->dropColumn([
                'fiscal_year', 'sequential_number', 'total_paid', 'payment_method', 'payment_terms',
                'bank_name', 'bank_iban', 'vat_payability', 'withholding_tax_enabled',
                'withholding_tax_percent', 'withholding_tax_amount', 'stamp_duty_applied',
                'stamp_duty_amount', 'fund_enabled', 'fund_type', 'fund_percent', 'fund_amount',
            ]);
        });

        Schema::dropIfExists('document_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('document_sequences');
    }
};
