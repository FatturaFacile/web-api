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
        Schema::create('purchase_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('request_number', 50)->index();
            $table->string('department')->nullable();
            $table->string('status', 30)->default('pending')->index(); // pending, approved, rejected, ordered
            $table->decimal('total_estimated', 15, 2)->default(0);
            $table->date('requested_date');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'request_number']);
        });

        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('supplier_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignUuid('purchase_request_id')->nullable()->constrained('purchase_requests')->nullOnDelete();
            $table->string('order_number', 50)->index();
            $table->date('date');
            $table->date('delivery_due_date')->nullable();
            $table->string('status', 30)->default('draft')->index(); // draft, sent, received, closed, cancelled
            $table->string('currency', 3)->default('EUR');
            $table->decimal('total_net', 15, 2)->default(0);
            $table->decimal('total_tax', 15, 2)->default(0);
            $table->decimal('total_gross', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'order_number']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->uuid('product_id')->nullable()->index();
            $table->string('description');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('passive_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('supplier_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('invoice_number', 50)->index();
            $table->date('date');
            $table->date('due_date')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->string('sdi_lot_id', 50)->nullable()->index();
            $table->boolean('is_reconciled')->default(false)->index();
            $table->longText('xml_payload')->nullable(); // Raw FatturaPA XML
            $table->text('qr_raw_payload')->nullable();  // Raw Swiss QR payload
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'date']);
            $table->index(['tenant_id', 'is_reconciled']);
        });

        Schema::create('expense_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->date('date');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->string('status', 30)->default('draft')->index(); // draft, submitted, approved, reimbursed
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'user_id', 'status']);
        });

        Schema::create('expense_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('expense_report_id')->constrained('expense_reports')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('EUR');
            $table->string('category', 50)->index(); // travel, meals, lodging, supplies, fuel
            $table->string('receipt_path')->nullable();
            $table->boolean('is_mileage')->default(false);
            $table->decimal('kilometers', 8, 2)->nullable();
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_items');
        Schema::dropIfExists('expense_reports');
        Schema::dropIfExists('passive_invoices');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('purchase_requests');
    }
};
