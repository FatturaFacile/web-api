<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GH-137: blanket agreements (call-off contracts), ATP/CTP metadata, backorder lines, credit-release trail,
 * replenishment pegging and a sales order audit trail. It extends the existing sales order tables (sales_orders,
 * sales_order_items) instead of duplicating them; stock reservations stay in the inventory module.
 * Dialect-agnostic: plain columns only (SQLite locally, PostgreSQL in CI/production).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blanket_agreements')) {
            Schema::create('blanket_agreements', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('agreement_number', 40);
                $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
                $table->string('currency_code', 3)->default('EUR');
                $table->decimal('exchange_rate', 19, 6)->default(1);
                $table->string('agreement_type', 20); // quantity_based, value_based
                $table->date('valid_from');
                $table->date('valid_to');
                $table->string('status', 20)->default('draft'); // draft, active, suspended, exhausted, expired, closed
                $table->decimal('total_committed_amount', 19, 4)->default(0);
                $table->decimal('total_called_off_amount', 19, 4)->default(0);
                $table->string('pricing_lock_mode', 30)->default('fixed_contract_price'); // fixed_contract_price, spot_price_with_discount
                $table->decimal('over_release_tolerance_percent', 5, 2)->default(0);
                $table->string('payment_terms', 100)->nullable();
                $table->string('incoterms', 20)->nullable();
                $table->text('notes')->nullable();
                $table->foreignUuid('renewed_from_id')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'agreement_number'], 'blanket_agreements_tenant_number_unique');
                $table->index(['tenant_id', 'contact_id']);
                $table->index(['tenant_id', 'status', 'valid_to']);
            });
        }

        if (! Schema::hasTable('blanket_agreement_lines')) {
            Schema::create('blanket_agreement_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('blanket_agreement_id')->constrained('blanket_agreements')->cascadeOnDelete();
                $table->unsignedInteger('line_number');
                $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->string('description', 255);
                $table->string('unit_of_measure', 20)->default('EA');
                $table->decimal('target_quantity', 19, 4)->default(0);
                $table->decimal('called_off_quantity', 19, 4)->default(0);
                $table->decimal('delivered_quantity', 19, 4)->default(0);
                $table->decimal('unit_price', 19, 4)->default(0);
                $table->decimal('discount_percent', 7, 4)->default(0);
                $table->decimal('tax_rate', 7, 4)->default(0);
                $table->decimal('target_amount', 19, 4)->default(0);
                $table->decimal('called_off_amount', 19, 4)->default(0);
                $table->json('delivery_schedule')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['blanket_agreement_id', 'line_number'], 'blanket_agreement_lines_number_unique');
                $table->index(['tenant_id', 'product_id']);
            });
        }

        Schema::table('sales_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_orders', 'order_type')) {
                $table->string('order_type', 20)->default('standard'); // standard, call_off, rush, dropship
                $table->uuid('blanket_agreement_id')->nullable();
                $table->uuid('warehouse_id')->nullable();
                $table->date('requested_delivery_date')->nullable();
                $table->date('confirmed_delivery_date')->nullable();
                $table->string('credit_block_reason', 40)->nullable(); // credit_limit_exceeded, overdue_invoices_present, both
                $table->uuid('credit_approved_by')->nullable();
                $table->timestamp('credit_approved_at')->nullable();
                $table->text('credit_override_notes')->nullable();
                $table->decimal('allocated_percentage', 7, 2)->default(0);
                $table->decimal('delivered_percentage', 7, 2)->default(0);
                $table->decimal('invoiced_percentage', 7, 2)->default(0);

                $table->index(['tenant_id', 'order_type']);
                $table->index('blanket_agreement_id');
            }
        });

        Schema::table('sales_order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_order_items', 'blanket_agreement_line_id')) {
                $table->uuid('blanket_agreement_line_id')->nullable();
                $table->uuid('warehouse_id')->nullable();
                $table->uuid('parent_line_id')->nullable();
                $table->boolean('is_backorder')->default(false);
                $table->unsignedInteger('backorder_sequence')->default(0);
                $table->decimal('quantity_cancelled', 19, 4)->default(0);
                $table->date('requested_date')->nullable();
                $table->date('atp_promised_date')->nullable();
                $table->string('atp_status', 30)->nullable(); // available_on_time, partial_availability, delayed_atp, ctp_required, shortage
                $table->boolean('replenishment_triggered')->default(false);
                $table->string('replenishment_reference_type', 30)->nullable(); // purchase_requisition, production_order
                $table->uuid('replenishment_reference_id')->nullable();

                $table->index('parent_line_id');
                $table->index('blanket_agreement_line_id');
                $table->index(['tenant_id', 'is_backorder']);
            }
        });

        if (! Schema::hasTable('sales_order_audit_logs')) {
            Schema::create('sales_order_audit_logs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
                $table->uuid('sales_order_item_id')->nullable();
                $table->string('event', 40); // status_changed, atp_recalculated, credit_blocked, credit_released, backorder_split, replenishment_triggered, call_off_balance_restored
                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30)->nullable();
                $table->uuid('actor_id')->nullable();
                $table->json('payload')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'sales_order_id', 'created_at'], 'sales_order_audit_logs_order_idx');
            });
        }

        if (! Schema::hasTable('replenishment_requests')) {
            Schema::create('replenishment_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
                $table->foreignUuid('sales_order_item_id')->constrained('sales_order_items')->cascadeOnDelete();
                $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->uuid('warehouse_id')->nullable();
                $table->string('request_type', 30); // purchase_requisition, production_order
                $table->decimal('quantity', 19, 4);
                $table->date('needed_by')->nullable();
                $table->string('status', 20)->default('open'); // open, converted, cancelled
                $table->string('target_type', 40)->nullable();
                $table->uuid('target_id')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
                $table->index('sales_order_item_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('replenishment_requests');
        Schema::dropIfExists('sales_order_audit_logs');
        Schema::dropIfExists('blanket_agreement_lines');
        Schema::dropIfExists('blanket_agreements');
    }
};
