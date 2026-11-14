<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Returns and warranty (RMA): settings and reason codes, customer return authorisations with their lines, the
     * warranty registry with its claims and supplier returns (RTV) with their lines. New tables only; the state
     * machines and the quantity rules are enforced by the application, not by the engine.
     */
    public function up(): void
    {
        if (! Schema::hasTable('returns_settings')) {
            Schema::create('returns_settings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->unsignedSmallInteger('return_window_days')->default(30);
                $table->timestamps();
                $table->softDeletes();

                $table->unique('tenant_id');
            });
        }

        if (! Schema::hasTable('returns_reason_codes')) {
            Schema::create('returns_reason_codes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 40);
                $table->string('label', 120);
                $table->boolean('is_defect')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('returns_rmas')) {
            Schema::create('returns_rmas', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('rma_number', 40);
                $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
                $table->uuid('invoice_id')->nullable()->index();
                $table->uuid('sales_order_id')->nullable()->index();
                $table->uuid('warehouse_id')->nullable();
                $table->string('status', 20)->default('requested');
                $table->string('reason_code', 40);
                $table->date('requested_on');
                $table->boolean('window_overridden')->default(false);
                $table->timestamp('approved_at')->nullable();
                $table->uuid('approved_by')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamp('inspected_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->string('resolution_type', 20)->nullable();
                $table->decimal('refund_amount', 15, 2)->nullable();
                $table->string('credit_note_reference', 100)->nullable();
                $table->text('resolution_notes')->nullable();
                $table->text('notes')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'rma_number']);
                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'contact_id']);
                $table->index(['tenant_id', 'requested_on']);
            });
        }

        if (! Schema::hasTable('returns_rma_lines')) {
            Schema::create('returns_rma_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('rma_id')->constrained('returns_rmas')->cascadeOnDelete();
                $table->uuid('invoice_item_id')->nullable()->index();
                $table->uuid('sales_order_item_id')->nullable()->index();
                $table->uuid('product_id')->index();
                $table->string('description');
                $table->decimal('quantity', 12, 4);
                $table->decimal('quantity_received', 12, 4)->default(0);
                $table->decimal('quantity_inspected', 12, 4)->default(0);
                $table->decimal('unit_price', 15, 4)->default(0);
                $table->string('serial_number', 100)->nullable();
                $table->string('lot_number', 100)->nullable();
                $table->string('reason_code', 40)->nullable();
                $table->string('disposition', 30)->nullable();
                $table->uuid('disposition_warehouse_id')->nullable();
                $table->uuid('supplier_id')->nullable();
                $table->text('inspection_notes')->nullable();
                $table->uuid('stock_movement_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'rma_id']);
                $table->index(['tenant_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('returns_warranties')) {
            Schema::create('returns_warranties', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('product_id')->index();
                $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
                $table->uuid('invoice_id')->nullable();
                $table->string('serial_number', 100)->nullable();
                $table->string('lot_number', 100)->nullable();
                $table->date('starts_on');
                $table->date('ends_on');
                $table->string('coverage_type', 20)->default('full');
                $table->text('coverage_terms')->nullable();
                $table->string('status', 20)->default('active');
                $table->text('void_reason')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'ends_on']);
                $table->index(['tenant_id', 'serial_number']);
                $table->index(['tenant_id', 'contact_id']);
            });
        }

        if (! Schema::hasTable('returns_warranty_claims')) {
            Schema::create('returns_warranty_claims', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('claim_number', 40);
                $table->foreignUuid('warranty_id')->constrained('returns_warranties')->cascadeOnDelete();
                $table->uuid('rma_id')->nullable()->index();
                $table->date('claim_date');
                $table->text('description');
                $table->string('status', 20)->default('open');
                $table->boolean('is_valid')->default(false);
                $table->string('validity_note', 190)->nullable();
                $table->text('decision_notes')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'claim_number']);
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('returns_supplier_returns')) {
            Schema::create('returns_supplier_returns', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('return_number', 40);
                $table->uuid('supplier_id')->index();
                $table->uuid('warehouse_id')->nullable();
                $table->uuid('rma_id')->nullable()->index();
                $table->string('status', 20)->default('draft');
                $table->string('reason_code', 40)->nullable();
                $table->string('carrier', 100)->nullable();
                $table->string('tracking_number', 100)->nullable();
                $table->string('currency', 3)->default('EUR');
                $table->decimal('credit_expected', 15, 2)->default(0);
                $table->decimal('credit_received', 15, 2)->nullable();
                $table->timestamp('shipped_at')->nullable();
                $table->timestamp('credited_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('notes')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'return_number']);
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('returns_supplier_return_lines')) {
            Schema::create('returns_supplier_return_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('supplier_return_id')->constrained('returns_supplier_returns')->cascadeOnDelete();
                $table->uuid('product_id')->index();
                $table->string('description')->nullable();
                $table->decimal('quantity', 12, 4);
                $table->decimal('unit_cost', 15, 4)->default(0);
                $table->uuid('goods_receipt_item_id')->nullable()->index();
                $table->uuid('rma_line_id')->nullable();
                $table->string('serial_number', 100)->nullable();
                $table->string('lot_number', 100)->nullable();
                $table->uuid('stock_movement_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'supplier_return_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('returns_supplier_return_lines');
        Schema::dropIfExists('returns_supplier_returns');
        Schema::dropIfExists('returns_warranty_claims');
        Schema::dropIfExists('returns_warranties');
        Schema::dropIfExists('returns_rma_lines');
        Schema::dropIfExists('returns_rmas');
        Schema::dropIfExists('returns_reason_codes');
        Schema::dropIfExists('returns_settings');
    }
};
