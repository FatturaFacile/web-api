<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement at enterprise level, additive only: approval and workflow tracking on orders and requisitions,
 * tolerances, supplier qualification documents, framework agreement lines, requests for quotation with supplier
 * quotes, quality inspection on receipt, returns with credit note requests, landed costs, purchase suggestions and the
 * payment block of supplier invoices that fail the three-way match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('workflow_instance_id')->nullable()->index();
            $table->uuid('rfq_id')->nullable()->index();
        });

        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->uuid('supplier_contract_line_id')->nullable()->index();
            $table->decimal('quantity_returned', 12, 4)->default(0);
            $table->decimal('unit_weight', 12, 4)->nullable();
            $table->decimal('list_unit_price', 15, 4)->nullable();
            $table->date('expected_delivery_date')->nullable();
        });

        Schema::table('purchase_requisitions', function (Blueprint $table): void {
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('workflow_instance_id')->nullable()->index();
        });

        Schema::table('purchase_receipts', function (Blueprint $table): void {
            $table->boolean('inspection_required')->default(false);
            $table->timestampTz('inspected_at')->nullable();
            $table->uuid('inspected_by')->nullable();
            $table->decimal('landed_cost_amount', 15, 2)->default(0);
        });

        Schema::table('purchase_receipt_lines', function (Blueprint $table): void {
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->string('inspection_status', 30)->default('not_required');
            $table->decimal('quantity_released', 12, 4)->default(0);
            $table->decimal('quantity_returned', 12, 4)->default(0);
            $table->jsonb('serial_numbers')->nullable();
            $table->uuid('warehouse_bin_id')->nullable();
            $table->decimal('landed_cost_amount', 15, 2)->default(0);
            $table->text('inspection_notes')->nullable();
        });

        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->timestampTz('credit_note_requested_at')->nullable();
            $table->string('credit_note_reference', 100)->nullable();
            $table->uuid('credit_note_passive_invoice_id')->nullable()->index();
            $table->uuid('journal_entry_id')->nullable();
        });

        Schema::table('purchase_return_lines', function (Blueprint $table): void {
            $table->uuid('purchase_receipt_line_id')->nullable()->index();
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('qualification_status', 30)->default('qualified')->index();
            $table->date('qualified_at')->nullable();
            $table->date('qualification_review_date')->nullable();
            $table->decimal('over_receipt_tolerance_percent', 5, 2)->nullable();
            $table->decimal('price_tolerance_percent', 5, 2)->nullable();
        });

        Schema::table('three_way_match_logs', function (Blueprint $table): void {
            $table->decimal('tolerance_percent', 5, 2)->nullable();
            $table->decimal('tolerance_amount', 15, 2)->nullable();
            $table->timestampTz('override_at')->nullable();
            $table->text('override_reason')->nullable();
        });

        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->uuid('purchase_order_id')->nullable()->index();
            $table->string('match_status', 30)->nullable()->index();
            $table->string('approval_status_before_block', 30)->nullable();
        });

        Schema::create('procurement_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->decimal('over_receipt_tolerance_percent', 5, 2)->default(0);
            $table->decimal('under_receipt_tolerance_percent', 5, 2)->default(0);
            $table->decimal('price_tolerance_percent', 5, 2)->default(0);
            $table->decimal('price_tolerance_amount', 15, 2)->default(0);
            $table->boolean('block_invoice_on_mismatch')->default(true);
            $table->string('landed_cost_clearing_account_code', 20)->default('2015');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('supplier_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('document_type', 50);
            $table->string('reference', 150)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable()->index();
            $table->boolean('is_mandatory')->default(false);
            $table->uuid('attachment_id')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'supplier_id', 'document_type'], 'idx_supp_docs_type');
        });

        Schema::create('supplier_contract_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('supplier_contract_id')->constrained('supplier_contracts')->cascadeOnDelete();
            $table->foreignUuid('supplier_catalog_id')->nullable()->constrained('supplier_catalogs')->nullOnDelete();
            $table->string('description');
            $table->string('uom', 20)->default('PCE');
            $table->decimal('unit_price', 15, 4);
            $table->decimal('tax_rate', 5, 2)->default(22);
            $table->decimal('committed_quantity', 12, 4)->nullable();
            $table->decimal('released_quantity', 12, 4)->default(0);
            $table->date('price_valid_from');
            $table->date('price_valid_to')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('procurement_rfqs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('company_id')->nullable()->index();
            $table->string('rfq_number', 100);
            $table->string('title', 255);
            $table->foreignUuid('purchase_requisition_id')->nullable()->constrained('purchase_requisitions')->nullOnDelete();
            $table->string('status', 30)->default('draft')->index();
            $table->date('issue_date')->nullable();
            $table->date('response_deadline');
            $table->string('currency', 3)->default('EUR');
            $table->uuid('cost_center_id')->nullable();
            $table->uuid('awarded_supplier_id')->nullable();
            $table->uuid('awarded_quote_id')->nullable();
            $table->uuid('purchase_order_id')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'rfq_number'], 'uq_rfq_number');
        });

        Schema::create('procurement_rfq_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('rfq_id')->constrained('procurement_rfqs')->cascadeOnDelete();
            $table->integer('line_number');
            $table->foreignUuid('purchase_requisition_line_id')->nullable()->constrained('purchase_requisition_lines')->nullOnDelete();
            $table->string('internal_sku', 100)->nullable();
            $table->string('description');
            $table->decimal('quantity', 12, 4);
            $table->string('uom', 20)->default('PCE');
            $table->date('required_by_date')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('procurement_rfq_suppliers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('rfq_id')->constrained('procurement_rfqs')->cascadeOnDelete();
            $table->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('status', 30)->default('invited');
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['rfq_id', 'supplier_id'], 'uq_rfq_supplier');
        });

        Schema::create('procurement_supplier_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('rfq_id')->constrained('procurement_rfqs')->cascadeOnDelete();
            $table->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('quote_reference', 100)->nullable();
            $table->date('quote_date');
            $table->date('valid_until')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->integer('lead_time_days')->nullable();
            $table->string('status', 30)->default('received')->index();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['rfq_id', 'supplier_id'], 'uq_quote_rfq_supplier');
        });

        Schema::create('procurement_supplier_quote_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('supplier_quote_id')->constrained('procurement_supplier_quotes')->cascadeOnDelete();
            $table->foreignUuid('rfq_line_id')->constrained('procurement_rfq_lines')->cascadeOnDelete();
            $table->decimal('quantity', 12, 4);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('tax_rate', 5, 2)->default(22);
            $table->decimal('line_total', 15, 2);
            $table->integer('lead_time_days')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('procurement_landed_costs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('company_id')->nullable()->index();
            $table->string('landed_cost_number', 100);
            $table->string('cost_type', 30);
            $table->string('allocation_method', 20);
            $table->string('status', 20)->default('draft')->index();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('EUR');
            $table->uuid('passive_invoice_id')->nullable()->index();
            $table->uuid('supplier_id')->nullable();
            $table->date('cost_date');
            $table->string('description')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'landed_cost_number'], 'uq_landed_cost_number');
        });

        Schema::create('procurement_landed_cost_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('landed_cost_id')->constrained('procurement_landed_costs')->cascadeOnDelete();
            $table->foreignUuid('purchase_receipt_id')->constrained('purchase_receipts')->restrictOnDelete();
            $table->foreignUuid('purchase_receipt_line_id')->constrained('purchase_receipt_lines')->restrictOnDelete();
            $table->uuid('stock_item_id')->nullable();
            $table->decimal('basis_value', 18, 4);
            $table->decimal('allocated_amount', 15, 2);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('procurement_purchase_suggestions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('source', 20);
            $table->uuid('source_id')->nullable();
            $table->uuid('stock_item_id')->nullable()->index();
            $table->uuid('warehouse_id')->nullable();
            $table->uuid('supplier_id')->nullable();
            $table->uuid('supplier_catalog_id')->nullable();
            $table->string('description');
            $table->decimal('quantity', 12, 4);
            $table->string('uom', 20)->default('PCE');
            $table->decimal('unit_price', 15, 4)->nullable();
            $table->date('required_by_date')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->uuid('purchase_order_id')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'source', 'source_id', 'status'], 'idx_purch_sugg_source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_purchase_suggestions');
        Schema::dropIfExists('procurement_landed_cost_allocations');
        Schema::dropIfExists('procurement_landed_costs');
        Schema::dropIfExists('procurement_supplier_quote_lines');
        Schema::dropIfExists('procurement_supplier_quotes');
        Schema::dropIfExists('procurement_rfq_suppliers');
        Schema::dropIfExists('procurement_rfq_lines');
        Schema::dropIfExists('procurement_rfqs');
        Schema::dropIfExists('supplier_contract_lines');
        Schema::dropIfExists('supplier_documents');
        Schema::dropIfExists('procurement_settings');

        $drops = [
            'passive_invoices' => ['purchase_order_id', 'match_status', 'approval_status_before_block'],
            'three_way_match_logs' => ['tolerance_percent', 'tolerance_amount', 'override_at', 'override_reason'],
            'suppliers' => ['qualification_status', 'qualified_at', 'qualification_review_date', 'over_receipt_tolerance_percent', 'price_tolerance_percent'],
            'purchase_return_lines' => ['purchase_receipt_line_id'],
            'purchase_returns' => ['credit_note_requested_at', 'credit_note_reference', 'credit_note_passive_invoice_id', 'journal_entry_id'],
            'purchase_receipt_lines' => ['unit_cost', 'inspection_status', 'quantity_released', 'quantity_returned', 'serial_numbers', 'warehouse_bin_id', 'landed_cost_amount', 'inspection_notes'],
            'purchase_receipts' => ['inspection_required', 'inspected_at', 'inspected_by', 'landed_cost_amount'],
            'purchase_requisitions' => ['submitted_at', 'approved_at', 'workflow_instance_id'],
            'purchase_order_lines' => ['supplier_contract_line_id', 'quantity_returned', 'unit_weight', 'list_unit_price', 'expected_delivery_date'],
            'purchase_orders' => ['submitted_at', 'approved_at', 'approved_by', 'sent_at', 'closed_at', 'workflow_instance_id', 'rfq_id'],
        ];

        foreach ($drops as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
