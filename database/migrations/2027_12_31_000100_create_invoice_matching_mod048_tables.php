<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice matching (Mod 048): configurable tolerance rules, the line-by-line 2-way / 3-way matching runs of supplier
 * invoices against purchase orders and goods receipts, and the exception resolution history. Dialect-agnostic: runs
 * unchanged on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            // Service and consulting lines are never physically received: they are matched against the order only (2-way).
            $table->boolean('receipt_required')->default(true);
        });

        Schema::table('supplier_catalogs', function (Blueprint $table): void {
            $table->string('item_category', 60)->nullable()->index();
        });

        Schema::create('invoice_match_tolerance_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('name', 120);
            $table->string('scope', 20); // tenant, supplier, item_category, rule
            $table->uuid('supplier_id')->nullable()->index();
            $table->string('item_category', 60)->nullable();
            $table->string('match_type', 10)->nullable(); // 2_way, 3_way; null applies to both
            $table->unsignedSmallInteger('priority')->default(100); // lower wins inside the same scope
            $table->decimal('price_tolerance_percent', 5, 2)->default(0);
            $table->decimal('price_tolerance_amount', 15, 2)->default(0);
            $table->decimal('quantity_tolerance_percent', 5, 2)->default(0);
            $table->decimal('quantity_tolerance_amount', 15, 4)->default(0);
            $table->string('currency', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'scope', 'is_active'], 'imtr_tenant_scope_active');
        });

        Schema::create('invoice_matching_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('company_id')->nullable();
            $table->uuid('passive_invoice_id')->index();
            $table->uuid('purchase_order_id')->nullable()->index();
            $table->uuid('supplier_id')->nullable();
            $table->string('match_type', 10); // 2_way, 3_way
            $table->string('status', 30)->index(); // matched, within_tolerance, blocked_discrepancy, pending_credit_note, override_approved, resolved
            $table->string('trigger', 12); // auto, manual, batch, rematch
            $table->string('reference_source', 20); // explicit, linked, xml, ebill, ocr, fallback, none
            $table->string('reference_value', 120)->nullable();
            $table->string('currency', 3)->nullable();
            $table->decimal('ordered_total', 15, 2)->default(0);
            $table->decimal('received_total', 15, 2)->default(0);
            $table->decimal('invoiced_total', 15, 2)->default(0);
            $table->decimal('variance_amount', 15, 2)->default(0);
            $table->decimal('variance_percent', 7, 2)->default(0);
            $table->decimal('gr_ir_amount', 15, 2)->default(0); // accrual cleared by the invoice
            $table->decimal('price_variance_amount', 15, 2)->default(0); // invoiced beyond the cleared accrual
            $table->unsignedSmallInteger('line_count')->default(0);
            $table->unsignedSmallInteger('discrepancy_count')->default(0);
            $table->boolean('is_current')->default(true);
            $table->json('reasons')->nullable();
            $table->json('tolerance_snapshot')->nullable();
            $table->timestamp('evaluated_at');
            $table->uuid('evaluated_by')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'status', 'evaluated_at'], 'imr_tenant_status_eval');
            $table->index(['passive_invoice_id', 'is_current'], 'imr_invoice_current');
        });

        Schema::create('invoice_match_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignUuid('run_id')->constrained('invoice_matching_runs')->cascadeOnDelete();
            $table->uuid('passive_invoice_line_id')->nullable();
            $table->uuid('purchase_order_line_id')->nullable()->index();
            $table->unsignedSmallInteger('line_number')->default(1);
            $table->string('description', 255)->nullable();
            $table->string('status', 20); // matched, within_tolerance, price_variance, quantity_variance, not_received, unmatched, accepted
            $table->boolean('receipt_required')->default(true);
            $table->decimal('ordered_quantity', 15, 4)->default(0);
            $table->decimal('received_quantity', 15, 4)->default(0);
            $table->decimal('previously_invoiced_quantity', 15, 4)->default(0);
            $table->decimal('invoiced_quantity', 15, 4)->default(0);
            $table->decimal('ordered_unit_price', 15, 6)->default(0);
            $table->decimal('invoiced_unit_price', 15, 6)->default(0);
            $table->decimal('quantity_variance', 15, 4)->default(0);
            $table->decimal('price_variance', 15, 6)->default(0);
            $table->decimal('price_variance_percent', 7, 2)->default(0);
            $table->decimal('amount_variance', 15, 2)->default(0);
            $table->decimal('allowed_quantity_variance', 15, 4)->default(0);
            $table->decimal('allowed_amount_variance', 15, 2)->default(0);
            $table->decimal('gr_ir_amount', 15, 2)->default(0);
            $table->uuid('tolerance_rule_id')->nullable();
            $table->json('reasons')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
        });

        Schema::create('invoice_match_resolutions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignUuid('run_id')->constrained('invoice_matching_runs')->cascadeOnDelete();
            $table->uuid('passive_invoice_id')->index();
            $table->string('action', 30); // request_credit_note, rematch, partial_resolution, override
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('reason')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('resolved_at');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_match_resolutions');
        Schema::dropIfExists('invoice_match_lines');
        Schema::dropIfExists('invoice_matching_runs');
        Schema::dropIfExists('invoice_match_tolerance_rules');

        Schema::table('supplier_catalogs', function (Blueprint $table): void {
            $table->dropIndex(['item_category']);
            $table->dropColumn('item_category');
        });

        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->dropColumn('receipt_required');
        });
    }
};
