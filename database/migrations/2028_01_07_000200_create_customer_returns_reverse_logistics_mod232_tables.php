<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 232 (Issue #478) — Advanced Customer Returns (Reverse Logistics), Advance Replacements (Cross-Shipping),
 * Refurbished / B-Stock Grading, and Credit Note VAT Time-Limit Control (Art. 26 DPR 633/72 & Art. 41 LIVA).
 * Dialect-agnostic: runs identically on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->extendReturnsSettings();
        $this->createCustomerReturns();
        $this->createCustomerReturnLines();
        $this->createAdvanceReplacements();
        $this->createReturnGradingInspections();
        $this->createRefurbishedItems();
    }

    public function down(): void
    {
        Schema::dropIfExists('refurbished_items');
        Schema::dropIfExists('return_grading_inspections');
        Schema::dropIfExists('advance_replacements');
        Schema::dropIfExists('customer_return_lines');
        Schema::dropIfExists('customer_returns');
    }

    private function extendReturnsSettings(): void
    {
        if (! Schema::hasTable('returns_settings')) {
            return;
        }

        Schema::table('returns_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('returns_settings', 'warranty_window_days')) {
                $table->unsignedSmallInteger('warranty_window_days')->default(730);
            }
            if (! Schema::hasColumn('returns_settings', 'default_restocking_fee_percent')) {
                $table->decimal('default_restocking_fee_percent', 5, 2)->default(10.00);
            }
            if (! Schema::hasColumn('returns_settings', 'advance_replacement_sla_days')) {
                $table->unsignedSmallInteger('advance_replacement_sla_days')->default(15);
            }
            if (! Schema::hasColumn('returns_settings', 'outlet_devaluation_grade_b_percent')) {
                $table->decimal('outlet_devaluation_grade_b_percent', 5, 2)->default(20.00);
            }
            if (! Schema::hasColumn('returns_settings', 'outlet_devaluation_grade_c_percent')) {
                $table->decimal('outlet_devaluation_grade_c_percent', 5, 2)->default(35.00);
            }
            if (! Schema::hasColumn('returns_settings', 'outlet_warehouse_id')) {
                $table->uuid('outlet_warehouse_id')->nullable();
            }
        });
    }

    private function createCustomerReturns(): void
    {
        if (Schema::hasTable('customer_returns')) {
            return;
        }

        Schema::create('customer_returns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('rma_id')->nullable()->index();
            $table->string('rma_number', 40);
            $table->string('rma_barcode', 64);
            $table->string('return_tracking_number', 100)->nullable();
            $table->string('carrier', 80)->nullable();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->uuid('invoice_id')->nullable()->index();
            $table->uuid('delivery_note_id')->nullable()->index();
            $table->uuid('sales_order_id')->nullable()->index();
            $table->uuid('warehouse_id')->nullable();
            $table->string('reason_code', 40);
            $table->string('channel', 30)->default('backoffice');
            $table->string('status', 30)->default('requested');
            $table->date('requested_on');
            $table->boolean('within_return_window')->default(true);
            $table->boolean('within_warranty_window')->default(true);
            $table->boolean('window_overridden')->default(false);
            $table->unsignedInteger('days_since_purchase')->default(0);
            $table->boolean('restocking_fee_applicable')->default(false);
            $table->decimal('restocking_fee_percent', 5, 2)->default(0);
            $table->decimal('restocking_fee_amount', 15, 2)->default(0);
            $table->decimal('gross_return_amount', 15, 2)->default(0);
            $table->decimal('net_refundable_amount', 15, 2)->default(0);
            $table->json('photo_urls')->nullable();
            $table->text('customer_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('resolution_type', 30)->nullable();
            $table->uuid('credit_note_id')->nullable()->index();
            $table->string('credit_note_reference', 100)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'rma_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'requested_on']);
        });
    }

    private function createCustomerReturnLines(): void
    {
        if (Schema::hasTable('customer_return_lines')) {
            return;
        }

        Schema::create('customer_return_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('customer_return_id')->constrained('customer_returns')->cascadeOnDelete();
            $table->uuid('invoice_item_id')->nullable()->index();
            $table->uuid('delivery_note_item_id')->nullable()->index();
            $table->uuid('sales_order_item_id')->nullable()->index();
            $table->uuid('product_id')->index();
            $table->string('description');
            $table->decimal('quantity', 12, 4);
            $table->decimal('quantity_received', 12, 4)->default(0);
            $table->decimal('quantity_inspected', 12, 4)->default(0);
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(22.00);
            $table->string('serial_number', 100)->nullable();
            $table->string('lot_number', 100)->nullable();
            $table->string('reason_code', 40)->nullable();
            $table->string('grade', 30)->nullable();
            $table->string('disposition', 40)->nullable();
            $table->json('photo_urls')->nullable();
            $table->text('inspection_notes')->nullable();
            $table->uuid('stock_movement_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'customer_return_id']);
            $table->index(['tenant_id', 'product_id']);
        });
    }

    private function createAdvanceReplacements(): void
    {
        if (Schema::hasTable('advance_replacements')) {
            return;
        }

        Schema::create('advance_replacements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('replacement_number', 40);
            $table->uuid('customer_return_id')->nullable()->index();
            $table->uuid('rma_id')->nullable()->index();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->uuid('original_invoice_id')->nullable()->index();
            $table->uuid('faulty_product_id')->index();
            $table->string('faulty_serial_number', 100)->nullable();
            $table->uuid('replacement_product_id')->index();
            $table->string('replacement_serial_number', 100)->nullable();
            $table->string('replacement_unit_type', 30)->default('new');
            $table->uuid('refurbished_item_id')->nullable()->index();
            $table->decimal('quantity', 12, 4)->default(1);
            $table->uuid('warehouse_id')->nullable();
            $table->uuid('outbound_delivery_note_id')->nullable();
            $table->uuid('outbound_stock_movement_id')->nullable();
            $table->string('status', 30)->default('pending_defective_return');
            $table->date('shipped_on');
            $table->unsignedSmallInteger('sla_days')->default(15);
            $table->date('return_due_on');
            $table->timestamp('defective_received_at')->nullable();
            $table->decimal('penalty_amount', 15, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->uuid('penalty_invoice_id')->nullable()->index();
            $table->string('penalty_invoice_number', 60)->nullable();
            $table->timestamp('billed_at')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'replacement_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'return_due_on']);
        });
    }

    private function createReturnGradingInspections(): void
    {
        if (Schema::hasTable('return_grading_inspections')) {
            return;
        }

        Schema::create('return_grading_inspections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('inspection_number', 40);
            $table->uuid('customer_return_id')->nullable()->index();
            $table->uuid('customer_return_line_id')->nullable()->index();
            $table->uuid('rma_id')->nullable()->index();
            $table->uuid('rma_line_id')->nullable()->index();
            $table->uuid('product_id')->index();
            $table->string('serial_number', 100)->nullable();
            $table->string('lot_number', 100)->nullable();
            $table->decimal('quantity_inspected', 12, 4)->default(1);
            $table->string('grade', 30);
            $table->string('disposition', 40);
            $table->json('checklist')->nullable();
            $table->json('photo_urls');
            $table->decimal('original_unit_cost', 15, 4)->default(0);
            $table->decimal('devaluation_percent', 5, 2)->default(0);
            $table->decimal('revalued_unit_cost', 15, 4)->default(0);
            $table->uuid('target_warehouse_id')->nullable();
            $table->uuid('supplier_id')->nullable();
            $table->uuid('supplier_return_id')->nullable();
            $table->uuid('refurbished_item_id')->nullable()->index();
            $table->uuid('stock_movement_id')->nullable();
            $table->text('inspector_notes')->nullable();
            $table->uuid('inspected_by')->nullable();
            $table->timestamp('inspected_at');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'inspection_number']);
            $table->index(['tenant_id', 'grade']);
            $table->index(['tenant_id', 'product_id']);
        });
    }

    private function createRefurbishedItems(): void
    {
        if (Schema::hasTable('refurbished_items')) {
            return;
        }

        Schema::create('refurbished_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('refurbished_sku', 80);
            $table->uuid('original_product_id')->index();
            $table->uuid('refurbished_product_id')->nullable()->index();
            $table->uuid('return_grading_inspection_id')->nullable()->index();
            $table->uuid('customer_return_id')->nullable()->index();
            $table->uuid('rma_id')->nullable()->index();
            $table->string('grade', 20);
            $table->string('serial_number', 100)->nullable();
            $table->string('lot_number', 100)->nullable();
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('original_cost', 15, 4)->default(0);
            $table->decimal('devaluation_percent', 5, 2)->default(20.00);
            $table->decimal('refurbishment_cost', 15, 4)->default(0);
            $table->decimal('new_unit_cost', 15, 4)->default(0);
            $table->decimal('suggested_sale_price', 15, 4)->default(0);
            $table->uuid('warehouse_id')->nullable();
            $table->string('work_order_status', 30)->default('completed');
            $table->json('work_order_steps')->nullable();
            $table->uuid('stock_movement_id')->nullable();
            $table->boolean('is_available')->default(true);
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'refurbished_sku']);
            $table->index(['tenant_id', 'grade']);
            $table->index(['tenant_id', 'original_product_id']);
        });
    }
};
