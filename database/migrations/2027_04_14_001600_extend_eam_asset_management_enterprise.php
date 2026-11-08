<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise extension of the asset management module (additive only, safe for `migrate --force`).
 *
 * - Parallel books (`eam_asset_books`): one row per asset and ledger (statutory = civil, tax = fiscal, ifrs,
 *   management) with its own method, rate, pro-rata convention and values.
 * - Fiscal depreciation parameters and pro-rata convention on the categories: rates are configured by the tenant, no
 *   coefficient is shipped by default.
 * - Acquisition sources (passive invoice line, purchase order item), cost centres, warranty and insurance coverage.
 * - Meter readings, meter-based maintenance plans, spare parts consumed by work orders and downtime timestamps.
 * - Partial disposals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eam_asset_categories', function (Blueprint $table): void {
            $this->addColumn($table, 'eam_asset_categories', 'pro_rata_convention', fn () => $table->string('pro_rata_convention', 20)->default('monthly'));
            $this->addColumn($table, 'eam_asset_categories', 'declining_balance_factor', fn () => $table->decimal('declining_balance_factor', 6, 4)->nullable());
            $this->addColumn($table, 'eam_asset_categories', 'fiscal_depreciation_method', fn () => $table->string('fiscal_depreciation_method', 50)->nullable());
            $this->addColumn($table, 'eam_asset_categories', 'fiscal_rate_percent', fn () => $table->decimal('fiscal_rate_percent', 7, 4)->nullable());
            $this->addColumn($table, 'eam_asset_categories', 'fiscal_useful_life_years', fn () => $table->unsignedInteger('fiscal_useful_life_years')->nullable());
            $this->addColumn($table, 'eam_asset_categories', 'fiscal_pro_rata_convention', fn () => $table->string('fiscal_pro_rata_convention', 20)->nullable());
            $this->addColumn($table, 'eam_asset_categories', 'rate_reference', fn () => $table->string('rate_reference', 500)->nullable());
            $this->addColumn($table, 'eam_asset_categories', 'gl_disposal_proceeds_code', fn () => $table->string('gl_disposal_proceeds_code', 50)->nullable());
            $this->addColumn($table, 'eam_asset_categories', 'gl_disposal_loss_code', fn () => $table->string('gl_disposal_loss_code', 50)->nullable());
        });

        Schema::table('eam_fixed_assets', function (Blueprint $table): void {
            $this->addColumn($table, 'eam_fixed_assets', 'cost_center_id', fn () => $table->uuid('cost_center_id')->nullable()->index());
            $this->addColumn($table, 'eam_fixed_assets', 'purchase_invoice_id', fn () => $table->uuid('purchase_invoice_id')->nullable()->index());
            $this->addColumn($table, 'eam_fixed_assets', 'purchase_invoice_line_id', fn () => $table->uuid('purchase_invoice_line_id')->nullable()->index());
            $this->addColumn($table, 'eam_fixed_assets', 'purchase_order_item_id', fn () => $table->uuid('purchase_order_item_id')->nullable()->index());
            $this->addColumn($table, 'eam_fixed_assets', 'capitalization_account_id', fn () => $table->uuid('capitalization_account_id')->nullable());
            $this->addColumn($table, 'eam_fixed_assets', 'depreciation_start_date', fn () => $table->date('depreciation_start_date')->nullable());
            $this->addColumn($table, 'eam_fixed_assets', 'pro_rata_convention', fn () => $table->string('pro_rata_convention', 20)->nullable());
            $this->addColumn($table, 'eam_fixed_assets', 'declining_balance_factor', fn () => $table->decimal('declining_balance_factor', 6, 4)->nullable());
            $this->addColumn($table, 'eam_fixed_assets', 'warranty_provider', fn () => $table->string('warranty_provider', 150)->nullable());
            $this->addColumn($table, 'eam_fixed_assets', 'warranty_alerted_at', fn () => $table->timestamp('warranty_alerted_at')->nullable());
            $this->addColumn($table, 'eam_fixed_assets', 'insurance_provider', fn () => $table->string('insurance_provider', 150)->nullable());
            $this->addColumn($table, 'eam_fixed_assets', 'insurance_policy_number', fn () => $table->string('insurance_policy_number', 100)->nullable());
            $this->addColumn($table, 'eam_fixed_assets', 'insured_value', fn () => $table->decimal('insured_value', 15, 2)->nullable());
            $this->addColumn($table, 'eam_fixed_assets', 'insurance_expiry_date', fn () => $table->date('insurance_expiry_date')->nullable()->index());
            $this->addColumn($table, 'eam_fixed_assets', 'insurance_alerted_at', fn () => $table->timestamp('insurance_alerted_at')->nullable());
        });

        Schema::table('eam_asset_transfers', function (Blueprint $table): void {
            $this->addColumn($table, 'eam_asset_transfers', 'from_cost_center_id', fn () => $table->uuid('from_cost_center_id')->nullable());
            $this->addColumn($table, 'eam_asset_transfers', 'to_cost_center_id', fn () => $table->uuid('to_cost_center_id')->nullable());
        });

        Schema::table('eam_asset_disposals', function (Blueprint $table): void {
            $this->addColumn($table, 'eam_asset_disposals', 'disposed_percentage', fn () => $table->decimal('disposed_percentage', 7, 4)->default(100));
            $this->addColumn($table, 'eam_asset_disposals', 'is_partial', fn () => $table->boolean('is_partial')->default(false));
        });

        Schema::table('eam_maintenance_plans', function (Blueprint $table): void {
            $this->addColumn($table, 'eam_maintenance_plans', 'trigger_type', fn () => $table->string('trigger_type', 20)->default('time'));
            $this->addColumn($table, 'eam_maintenance_plans', 'meter_code', fn () => $table->string('meter_code', 40)->nullable());
            $this->addColumn($table, 'eam_maintenance_plans', 'last_meter_reading', fn () => $table->decimal('last_meter_reading', 15, 2)->nullable());
            $this->addColumn($table, 'eam_maintenance_plans', 'next_due_meter', fn () => $table->decimal('next_due_meter', 15, 2)->nullable());
            $this->addColumn($table, 'eam_maintenance_plans', 'lead_time_days', fn () => $table->unsignedInteger('lead_time_days')->default(0));
        });

        Schema::table('eam_maintenance_work_orders', function (Blueprint $table): void {
            $this->addColumn($table, 'eam_maintenance_work_orders', 'downtime_started_at', fn () => $table->timestamp('downtime_started_at')->nullable());
            $this->addColumn($table, 'eam_maintenance_work_orders', 'downtime_ended_at', fn () => $table->timestamp('downtime_ended_at')->nullable());
            $this->addColumn($table, 'eam_maintenance_work_orders', 'estimated_cost', fn () => $table->decimal('estimated_cost', 15, 2)->default(0));
            $this->addColumn($table, 'eam_maintenance_work_orders', 'meter_reading_at_completion', fn () => $table->decimal('meter_reading_at_completion', 15, 2)->nullable());
            $this->addColumn($table, 'eam_maintenance_work_orders', 'cancelled_at', fn () => $table->timestamp('cancelled_at')->nullable());
            $this->addColumn($table, 'eam_maintenance_work_orders', 'journal_entry_id', fn () => $table->uuid('journal_entry_id')->nullable());
        });

        if (! Schema::hasTable('eam_asset_books')) {
            Schema::create('eam_asset_books', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('asset_id')->constrained('eam_fixed_assets')->cascadeOnDelete();
                $table->string('ledger_type', 30);
                $table->string('depreciation_method', 50)->default('straight_line');
                $table->unsignedInteger('useful_life_months')->default(60);
                $table->decimal('rate_percent', 7, 4)->nullable();
                $table->decimal('declining_balance_factor', 6, 4)->nullable();
                $table->string('pro_rata_convention', 20)->default('monthly');
                $table->date('depreciation_start_date')->nullable();
                $table->decimal('cost', 15, 2)->default(0);
                $table->decimal('salvage_value', 15, 2)->default(0);
                $table->decimal('depreciable_base', 15, 2)->default(0);
                $table->decimal('accumulated_depreciation', 15, 2)->default(0);
                $table->decimal('net_book_value', 15, 2)->default(0);
                $table->decimal('total_expected_units', 15, 2)->default(0);
                $table->decimal('consumed_units', 15, 2)->default(0);
                $table->string('last_period_code', 20)->nullable();
                $table->boolean('is_fully_depreciated')->default(false);
                $table->boolean('posts_to_gl')->default(false);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['tenant_id', 'asset_id', 'ledger_type'], 'eam_asset_books_ledger_unique');
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE eam_asset_books ADD CONSTRAINT chk_eam_asset_books_values CHECK (cost >= 0 AND salvage_value >= 0 AND accumulated_depreciation >= 0 AND net_book_value >= 0)');
            }
        }

        if (! Schema::hasTable('eam_asset_meter_readings')) {
            Schema::create('eam_asset_meter_readings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('asset_id')->constrained('eam_fixed_assets')->cascadeOnDelete();
                $table->string('meter_code', 40);
                $table->decimal('reading_value', 15, 2);
                $table->timestamp('read_at');
                $table->string('source', 30)->default('manual');
                $table->foreignUuid('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'asset_id', 'meter_code', 'read_at'], 'eam_meter_readings_lookup');
            });
        }

        if (! Schema::hasTable('eam_work_order_parts')) {
            Schema::create('eam_work_order_parts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('work_order_id')->constrained('eam_maintenance_work_orders')->cascadeOnDelete();
                $table->uuid('product_id')->index();
                $table->uuid('warehouse_id')->nullable();
                $table->decimal('quantity', 15, 4);
                $table->decimal('unit_cost', 15, 4)->default(0);
                $table->decimal('total_cost', 15, 2)->default(0);
                $table->uuid('stock_movement_id')->nullable();
                $table->foreignUuid('consumed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('consumed_at');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS eam_fixed_assets_invoice_line_unique ON eam_fixed_assets (tenant_id, purchase_invoice_line_id) WHERE purchase_invoice_line_id IS NOT NULL AND deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS eam_fixed_assets_po_item_unique ON eam_fixed_assets (tenant_id, purchase_order_item_id) WHERE purchase_order_item_id IS NOT NULL AND deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        // Non-destructive enterprise migration policy: the added columns and tables hold fiscal history.
    }

    /**
     * Adds a column only when it is missing, so the migration can run again on partially migrated installations.
     */
    private function addColumn(Blueprint $table, string $tableName, string $column, Closure $definition): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            $definition();
        }
    }
};
