<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GH-396: Vendor warranty claim-backs and loaner / repair-account register (Module 17 ESM).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('esm_vendor_warranty_claims')) {
            Schema::create('esm_vendor_warranty_claims', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('rma_order_id')->constrained('esm_rma_orders')->cascadeOnDelete();
                $table->foreignUuid('rma_line_id')->nullable()->constrained('esm_rma_lines')->nullOnDelete();
                $table->uuid('original_supplier_id')->index(); // suppliers (no FK)
                $table->string('defective_part_serial', 100)->nullable();
                $table->uuid('defective_product_id')->nullable()->index();
                $table->string('supplier_rma_reference', 100)->nullable();
                $table->string('status', 30)->default('draft')->index();
                $table->decimal('claimed_amount', 18, 2)->default(0.00);
                $table->string('currency', 3)->default('EUR');
                $table->string('credit_note_reference', 100)->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('esm_loaners')) {
            Schema::create('esm_loaners', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('rma_order_id')->constrained('esm_rma_orders')->cascadeOnDelete();
                $table->uuid('loaner_stock_serial_number_id')->nullable()->index(); // Module 14 StockSerialNumber (no FK)
                $table->string('loaner_serial_number', 100);
                $table->uuid('loaner_product_id')->nullable()->index();
                $table->uuid('customer_id')->index();
                $table->string('register_type', 30); // prestito_uso, conto_riparazione
                $table->timestamp('issued_at');
                $table->timestamp('expected_return_at')->nullable();
                $table->timestamp('returned_at')->nullable();
                $table->string('status', 20)->default('issued')->index(); // issued, returned, lost
                $table->string('ddt_causale', 40)->nullable();
                $table->timestamp('overdue_alerted_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'register_type', 'status'], 'esm_loaners_register_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('esm_loaners');
        Schema::dropIfExists('esm_vendor_warranty_claims');
    }
};
