<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Supplier Categories
        if (! Schema::hasTable('supplier_categories')) {
            Schema::create('supplier_categories', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 50);
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'code'], 'uq_supp_cat_code');
            });
        }

        // 2. Suppliers (Master Vendors & Approved Vendor List)
        if (! Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
                $table->foreignUuid('supplier_category_id')->nullable()->constrained('supplier_categories')->nullOnDelete();
                $table->string('supplier_code', 50);
                $table->string('company_name', 255);
                $table->string('vat_number', 50)->nullable()->index();
                $table->string('fiscal_code', 50)->nullable()->index();
                $table->string('currency', 3)->default('EUR');
                $table->string('payment_terms_code', 50)->nullable();
                $table->string('incoterms', 10)->nullable();
                $table->string('status', 50)->default('draft')->index(); // draft, pending_approval, approved, suspended
                $table->boolean('is_approved_vendor')->default(false)->index();
                $table->decimal('credit_limit', 15, 2)->default(0.00);
                $table->string('rating', 10)->default('B'); // A, B, C, D
                $table->boolean('is_locked')->default(false);
                $table->jsonb('metadata')->default('{}');
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'supplier_code'], 'uq_supplier_code');
                $table->index(['tenant_id', 'status', 'is_approved_vendor'], 'idx_supp_status_avl');
            });
        }

        // 3. Supplier Contacts
        if (! Schema::hasTable('supplier_contacts')) {
            Schema::create('supplier_contacts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->foreignUuid('supplier_id')->constrained('suppliers')->cascadeOnDelete();
                $table->string('name', 255);
                $table->string('email', 255)->nullable()->index();
                $table->string('phone', 50)->nullable();
                $table->string('role', 100)->nullable();
                $table->boolean('is_primary')->default(false);
                $table->timestampsTz();
                $table->softDeletesTz();
            });
        }

        // 4. Supplier Catalogs (Master Part Numbers & Lead Times)
        if (! Schema::hasTable('supplier_catalogs')) {
            Schema::create('supplier_catalogs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('supplier_id')->constrained('suppliers')->cascadeOnDelete();
                $table->string('supplier_part_number', 100);
                $table->string('internal_sku', 100)->nullable()->index();
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->string('uom', 20)->default('PCE');
                $table->integer('lead_time_days')->default(7);
                $table->decimal('minimum_order_quantity', 12, 4)->default(1.0000);
                $table->boolean('is_active')->default(true);
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'supplier_id', 'supplier_part_number'], 'uq_supp_cat_part');
            });
        }

        // 5. Supplier Price Lists (Tiered Volume Breaks)
        if (! Schema::hasTable('supplier_price_lists')) {
            Schema::create('supplier_price_lists', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->foreignUuid('supplier_catalog_id')->constrained('supplier_catalogs')->cascadeOnDelete();
                $table->string('currency', 3)->default('EUR');
                $table->decimal('min_quantity', 12, 4)->default(1.0000);
                $table->decimal('unit_price', 15, 4);
                $table->date('valid_from');
                $table->date('valid_to')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->index(['supplier_catalog_id', 'valid_from', 'valid_to'], 'idx_supp_price_validity');
            });
        }

        // 6. Supplier Contracts & Framework Agreements
        if (! Schema::hasTable('supplier_contracts')) {
            Schema::create('supplier_contracts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
                $table->string('contract_number', 100);
                $table->string('title', 255);
                $table->string('contract_type', 50)->default('framework'); // framework, blanket, fixed_price
                $table->decimal('total_committed_amount', 15, 2)->default(0.00);
                $table->decimal('consumed_amount', 15, 2)->default(0.00);
                $table->date('starts_at');
                $table->date('ends_at');
                $table->string('payment_terms_code', 50)->nullable();
                $table->string('incoterms', 10)->nullable();
                $table->string('status', 50)->default('draft')->index(); // draft, pending_approval, active, expired, terminated
                $table->boolean('is_locked')->default(false);
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'contract_number'], 'uq_supp_contract_num');
            });
        }

        // 7. Purchase Requisitions
        if (! Schema::hasTable('purchase_requisitions')) {
            Schema::create('purchase_requisitions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('branch_id')->nullable()->index();
                $table->uuid('department_id')->nullable()->index();
                $table->uuid('cost_center_id')->nullable()->index();
                $table->uuid('project_id')->nullable()->index();
                $table->string('requisition_number', 100);
                $table->string('title', 255);
                $table->foreignUuid('requestor_user_id')->constrained('users')->restrictOnDelete();
                $table->string('urgency', 50)->default('medium'); // low, medium, high, emergency
                $table->date('required_by_date');
                $table->string('status', 50)->default('draft')->index(); // draft, pending_approval, approved, rejected, converted_to_po, cancelled
                $table->decimal('total_estimated_amount', 15, 2)->default(0.00);
                $table->string('currency', 3)->default('EUR');
                $table->text('notes')->nullable();
                $table->boolean('is_locked')->default(false);
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'requisition_number'], 'uq_pr_number');
                $table->index(['tenant_id', 'status', 'requestor_user_id'], 'idx_pr_tenant_status');
            });
        }

        // 8. Purchase Requisition Lines
        if (! Schema::hasTable('purchase_requisition_lines')) {
            Schema::create('purchase_requisition_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->foreignUuid('purchase_requisition_id')->constrained('purchase_requisitions')->cascadeOnDelete();
                $table->integer('line_number')->default(1);
                $table->foreignUuid('supplier_catalog_id')->nullable()->constrained('supplier_catalogs')->nullOnDelete();
                $table->string('description');
                $table->decimal('quantity_requested', 12, 4);
                $table->string('uom', 20)->default('PCE');
                $table->decimal('estimated_unit_price', 15, 4)->default(0.0000);
                $table->decimal('estimated_total_price', 15, 2)->default(0.00);
                $table->foreignUuid('suggested_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
                $table->timestampsTz();
                $table->softDeletesTz();
            });
        }

        // 9. Evolve Purchase Orders with Enterprise Columns
        if (Schema::hasTable('purchase_orders')) {
            Schema::table('purchase_orders', function (Blueprint $table): void {
                if (! Schema::hasColumn('purchase_orders', 'company_id')) {
                    $table->uuid('company_id')->nullable()->index();
                }
                if (! Schema::hasColumn('purchase_orders', 'branch_id')) {
                    $table->uuid('branch_id')->nullable()->index();
                }
                if (! Schema::hasColumn('purchase_orders', 'department_id')) {
                    $table->uuid('department_id')->nullable()->index();
                }
                if (! Schema::hasColumn('purchase_orders', 'cost_center_id')) {
                    $table->uuid('cost_center_id')->nullable()->index();
                }
                if (! Schema::hasColumn('purchase_orders', 'project_id')) {
                    $table->uuid('project_id')->nullable()->index();
                }
                if (! Schema::hasColumn('purchase_orders', 'supplier_contract_id')) {
                    $table->foreignUuid('supplier_contract_id')->nullable()->constrained('supplier_contracts')->nullOnDelete();
                }
                if (! Schema::hasColumn('purchase_orders', 'purchase_requisition_id')) {
                    $table->foreignUuid('purchase_requisition_id')->nullable()->constrained('purchase_requisitions')->nullOnDelete();
                }
                if (! Schema::hasColumn('purchase_orders', 'po_number')) {
                    $table->string('po_number', 100)->nullable()->index();
                }
                if (! Schema::hasColumn('purchase_orders', 'order_type')) {
                    $table->string('order_type', 50)->default('standard'); // standard, blanket_release, subcontract
                }
                if (! Schema::hasColumn('purchase_orders', 'expected_delivery_date')) {
                    $table->date('expected_delivery_date')->nullable();
                }
                if (! Schema::hasColumn('purchase_orders', 'exchange_rate')) {
                    $table->decimal('exchange_rate', 18, 6)->default(1.000000);
                }
                if (! Schema::hasColumn('purchase_orders', 'net_amount')) {
                    $table->decimal('net_amount', 15, 2)->default(0.00);
                }
                if (! Schema::hasColumn('purchase_orders', 'tax_amount')) {
                    $table->decimal('tax_amount', 15, 2)->default(0.00);
                }
                if (! Schema::hasColumn('purchase_orders', 'total_amount')) {
                    $table->decimal('total_amount', 15, 2)->default(0.00);
                }
                if (! Schema::hasColumn('purchase_orders', 'budget_committed_amount')) {
                    $table->decimal('budget_committed_amount', 15, 2)->default(0.00);
                }
                if (! Schema::hasColumn('purchase_orders', 'payment_terms_code')) {
                    $table->string('payment_terms_code', 50)->nullable();
                }
                if (! Schema::hasColumn('purchase_orders', 'incoterms')) {
                    $table->string('incoterms', 10)->nullable();
                }
                if (! Schema::hasColumn('purchase_orders', 'shipping_address')) {
                    $table->jsonb('shipping_address')->default('{}');
                }
                if (! Schema::hasColumn('purchase_orders', 'is_budget_encumbered')) {
                    $table->boolean('is_budget_encumbered')->default(false)->index();
                }
                if (! Schema::hasColumn('purchase_orders', 'is_locked')) {
                    $table->boolean('is_locked')->default(false);
                }
                if (! Schema::hasColumn('purchase_orders', 'created_by')) {
                    $table->uuid('created_by')->nullable();
                }
                if (! Schema::hasColumn('purchase_orders', 'updated_by')) {
                    $table->uuid('updated_by')->nullable();
                }
            });
        }

        // 10. Purchase Order Lines
        if (! Schema::hasTable('purchase_order_lines')) {
            Schema::create('purchase_order_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
                $table->integer('line_number')->default(1);
                $table->foreignUuid('supplier_catalog_id')->nullable()->constrained('supplier_catalogs')->nullOnDelete();
                $table->string('description');
                $table->decimal('quantity_ordered', 12, 4);
                $table->decimal('quantity_received', 12, 4)->default(0.0000);
                $table->decimal('quantity_invoiced', 12, 4)->default(0.0000);
                $table->decimal('quantity_cancelled', 12, 4)->default(0.0000);
                $table->string('uom', 20)->default('PCE');
                $table->decimal('unit_price', 15, 4);
                $table->decimal('tax_rate', 5, 2)->default(22.00);
                $table->decimal('line_net_amount', 15, 2);
                $table->decimal('line_tax_amount', 15, 2);
                $table->decimal('line_total_amount', 15, 2);
                $table->boolean('is_closed')->default(false)->index();
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->index(['purchase_order_id', 'is_closed'], 'idx_pol_po_closed');
            });
        }

        // 11. Purchase Receipts (Goods Receipt Notes)
        if (! Schema::hasTable('purchase_receipts')) {
            Schema::create('purchase_receipts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
                $table->string('receipt_number', 100);
                $table->string('supplier_delivery_note_ref', 100);
                $table->date('received_date');
                $table->foreignUuid('received_by_user_id')->constrained('users')->restrictOnDelete();
                $table->uuid('warehouse_id')->nullable()->index(); // Module 14 integration
                $table->string('inspection_status', 50)->default('passed'); // passed, failed, conditional
                $table->text('notes')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'receipt_number'], 'uq_prc_receipt_number');
                $table->index(['tenant_id', 'purchase_order_id'], 'idx_prc_tenant_po');
            });
        }

        // 12. Purchase Receipt Lines
        if (! Schema::hasTable('purchase_receipt_lines')) {
            Schema::create('purchase_receipt_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->foreignUuid('purchase_receipt_id')->constrained('purchase_receipts')->cascadeOnDelete();
                $table->foreignUuid('purchase_order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
                $table->decimal('quantity_received', 12, 4);
                $table->decimal('quantity_accepted', 12, 4);
                $table->decimal('quantity_rejected', 12, 4)->default(0.0000);
                $table->string('lot_number', 100)->nullable();
                $table->string('serial_number', 100)->nullable();
                $table->uuid('warehouse_location_id')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();
            });
        }

        // 13. Purchase Returns
        if (! Schema::hasTable('purchase_returns')) {
            Schema::create('purchase_returns', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
                $table->foreignUuid('purchase_receipt_id')->nullable()->constrained('purchase_receipts')->nullOnDelete();
                $table->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
                $table->string('return_number', 100);
                $table->date('return_date');
                $table->foreignUuid('returned_by_user_id')->constrained('users')->restrictOnDelete();
                $table->string('status', 50)->default('draft')->index(); // draft, shipped, credited, cancelled
                $table->decimal('total_amount', 15, 2)->default(0.00);
                $table->string('reason', 255)->nullable();
                $table->text('notes')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'return_number'], 'uq_prt_return_number');
            });
        }

        // 14. Purchase Return Lines
        if (! Schema::hasTable('purchase_return_lines')) {
            Schema::create('purchase_return_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->foreignUuid('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
                $table->foreignUuid('purchase_order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
                $table->decimal('quantity_returned', 12, 4);
                $table->decimal('unit_price', 15, 4);
                $table->decimal('line_total', 15, 2);
                $table->text('reason')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();
            });
        }

        // 15. Three-Way Match Logs
        if (! Schema::hasTable('three_way_match_logs')) {
            Schema::create('three_way_match_logs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
                $table->foreignUuid('purchase_receipt_id')->constrained('purchase_receipts')->restrictOnDelete();
                $table->uuid('passive_invoice_id')->index(); // Link to incoming invoice
                $table->string('match_status', 50)->default('matched')->index(); // matched, price_variance, quantity_variance, rejected
                $table->decimal('po_total_amount', 15, 2);
                $table->decimal('grn_total_amount', 15, 2);
                $table->decimal('invoice_total_amount', 15, 2);
                $table->decimal('variance_amount', 15, 2)->default(0.00);
                $table->decimal('variance_percentage', 5, 2)->default(0.00);
                $table->jsonb('discrepancy_details')->default('[]');
                $table->timestampTz('evaluated_at')->useCurrent();
                $table->uuid('approved_by')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->index(['tenant_id', 'match_status', 'evaluated_at'], 'idx_twm_tenant_eval');
            });
        }

        // 16. Supplier Evaluations
        if (! Schema::hasTable('supplier_evaluations')) {
            Schema::create('supplier_evaluations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
                $table->date('evaluation_period_start');
                $table->date('evaluation_period_end');
                $table->decimal('on_time_delivery_rate', 5, 2)->default(100.00);
                $table->decimal('quality_acceptance_rate', 5, 2)->default(100.00);
                $table->decimal('price_competitiveness_score', 5, 2)->default(100.00);
                $table->string('overall_rating', 10)->default('A'); // A, B, C, D
                $table->boolean('is_approved_vendor')->default(true);
                $table->foreignUuid('evaluator_user_id')->constrained('users')->restrictOnDelete();
                $table->text('comments')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->index(['tenant_id', 'supplier_id', 'overall_rating'], 'idx_se_tenant_supp_rate');
            });
        }

        // 17. Supplier Scorecards (Quarterly Historical Performance Aggregate)
        if (! Schema::hasTable('supplier_scorecards')) {
            Schema::create('supplier_scorecards', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('supplier_id')->constrained('suppliers')->cascadeOnDelete();
                $table->integer('year');
                $table->integer('quarter');
                $table->decimal('otif_percentage', 5, 2)->default(100.00);
                $table->integer('defect_ppm')->default(0);
                $table->decimal('cost_savings_amount', 15, 2)->default(0.00);
                $table->decimal('compliance_score', 5, 2)->default(100.00);
                $table->string('risk_level', 20)->default('low'); // low, medium, high
                $table->boolean('is_avl_active')->default(true);
                $table->jsonb('scorecard_data')->default('{}');
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'supplier_id', 'year', 'quarter'], 'uq_supp_scorecard_qtr');
            });
        }

        // 18. GIN Indexes for High-Performance JSONB Queries (PostgreSQL 17)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_po_shipping_address_gin ON purchase_orders USING gin (shipping_address jsonb_path_ops)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_three_way_discrepancy_gin ON three_way_match_logs USING gin (discrepancy_details jsonb_path_ops)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_supplier_metadata_gin ON suppliers USING gin (metadata jsonb_path_ops)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_supplier_scorecard_data_gin ON supplier_scorecards USING gin (scorecard_data jsonb_path_ops)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_supplier_scorecard_data_gin');
            DB::statement('DROP INDEX IF EXISTS idx_supplier_metadata_gin');
            DB::statement('DROP INDEX IF EXISTS idx_three_way_discrepancy_gin');
            DB::statement('DROP INDEX IF EXISTS idx_po_shipping_address_gin');
        }

        Schema::dropIfExists('supplier_scorecards');
        Schema::dropIfExists('supplier_evaluations');
        Schema::dropIfExists('three_way_match_logs');
        Schema::dropIfExists('purchase_return_lines');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_receipt_lines');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_requisition_lines');
        Schema::dropIfExists('purchase_requisitions');
        Schema::dropIfExists('supplier_contracts');
        Schema::dropIfExists('supplier_price_lists');
        Schema::dropIfExists('supplier_catalogs');
        Schema::dropIfExists('supplier_contacts');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('supplier_categories');
    }
};
