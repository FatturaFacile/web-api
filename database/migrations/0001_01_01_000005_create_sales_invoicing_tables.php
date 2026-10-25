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
        Schema::create('quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('quote_number', 50)->index();
            $table->date('date');
            $table->date('valid_until')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('total_net', 15, 2)->default(0);
            $table->decimal('total_tax', 15, 2)->default(0);
            $table->decimal('total_gross', 15, 2)->default(0);
            $table->string('status', 30)->default('draft')->index(); // draft, sent, accepted, rejected, expired
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'quote_number']);
        });

        Schema::create('quote_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->uuid('product_id')->nullable()->index();
            $table->string('description');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('discount_percentage', 5, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('sales_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignUuid('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->string('order_number', 50)->index();
            $table->date('date');
            $table->date('due_date')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('total_net', 15, 2)->default(0);
            $table->decimal('total_tax', 15, 2)->default(0);
            $table->decimal('total_gross', 15, 2)->default(0);
            $table->string('status', 30)->default('draft')->index(); // draft, confirmed, in_production, shipped, completed, cancelled
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'order_number']);
        });

        Schema::create('sales_order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->uuid('product_id')->nullable()->index();
            $table->string('description');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('discount_percentage', 5, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->enum('invoice_type', ['standard', 'sdi_electronic', 'ch_qr_bill', 'credit_note', 'proforma'])->default('standard');
            $table->string('invoice_number', 50)->index();
            $table->date('date');
            $table->date('due_date')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('exchange_rate', 16, 6)->default(1.0);
            $table->decimal('total_net', 15, 2)->default(0);
            $table->decimal('total_tax', 15, 2)->default(0);
            $table->decimal('total_gross', 15, 2)->default(0);
            $table->decimal('rounding_amount', 15, 2)->default(0); // For Swiss 0.05 CHF rounding
            $table->enum('payment_status', ['unpaid', 'partially_paid', 'paid'])->default('unpaid')->index();
            $table->enum('jurisdiction', ['IT', 'CH'])->default('IT')->index();
            $table->boolean('split_payment')->default(false);
            $table->boolean('reverse_charge')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'invoice_number']);
            $table->index(['tenant_id', 'date']);
            $table->index(['tenant_id', 'payment_status']);
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->uuid('product_id')->nullable()->index();
            $table->string('description');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('discount_percentage', 5, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('invoice_sdi_metadata', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->unique()->constrained('invoices')->cascadeOnDelete();
            $table->string('transmission_format', 10)->default('FPR12'); // FPA12 (PA) or FPR12 (Privati/B2B)
            $table->string('sdi_identifier', 50)->nullable()->index();
            $table->string('sdi_status', 30)->default('draft')->index(); // draft, sending, delivered, failed, rejected, accepted
            $table->string('file_name')->nullable();
            $table->longText('xml_content')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_qr_bill_data', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->unique()->constrained('invoices')->cascadeOnDelete();
            $table->string('qr_iban', 34);
            $table->string('scor_reference', 35)->index(); // 27-digit SCOR reference
            $table->json('creditor_info');
            $table->json('debtor_info');
            $table->longText('qr_bill_svg')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_qr_bill_data');
        Schema::dropIfExists('invoice_sdi_metadata');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_orders');
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
    }
};
