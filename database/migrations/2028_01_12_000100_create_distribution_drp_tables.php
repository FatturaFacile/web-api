<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distribution requirements planning, multi-warehouse network and sales channel availability (Issue #271).
 *
 * Dialect-agnostic schema (SQLite and PostgreSQL). Transfers themselves stay in the inventory transfer order aggregate; these
 * tables hold the network topology, the lanes, the replenishment policies and proposals, the DRP snapshots, the consignment
 * settlements, the 3PL reconciliations and the sales channels.
 */
return new class extends Migration
{
    private function base(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
        $table->uuid('company_id')->nullable()->index();
    }

    private function audit(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    public function up(): void
    {
        if (! Schema::hasTable('distribution_warehouse_profiles')) {
            Schema::create('distribution_warehouse_profiles', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->string('role', 30)->default('internal')->index();
                $table->uuid('parent_warehouse_id')->nullable()->index();
                $table->uuid('default_transit_warehouse_id')->nullable();
                $table->uuid('third_party_partner_id')->nullable()->index();
                $table->string('external_reference', 120)->nullable();
                $table->string('settlement_trigger', 30)->nullable();
                $table->string('billing_frequency', 20)->nullable();
                $table->unsignedInteger('max_consignment_days')->nullable();
                $table->boolean('is_insured')->default(false);
                $table->boolean('counts_in_valuation')->default(true);
                $table->boolean('include_in_free_stock')->default(true);
                $table->boolean('is_blocked')->default(false);
                $table->boolean('is_counting')->default(false);
                $table->text('notes')->nullable();
                $this->audit($table);

                $table->unique(['tenant_id', 'warehouse_id'], 'uq_dist_profile_warehouse');
            });
        }

        if (! Schema::hasTable('distribution_lanes')) {
            Schema::create('distribution_lanes', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('source_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->foreignUuid('destination_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->unsignedInteger('lead_time_days')->default(1);
                $table->unsignedInteger('lead_time_hours')->default(0);
                $table->string('transport_mode', 20)->default('road');
                $table->decimal('cost_per_unit', 14, 4)->default(0);
                $table->boolean('is_active')->default(true);
                $this->audit($table);

                $table->unique(['tenant_id', 'source_warehouse_id', 'destination_warehouse_id', 'transport_mode'], 'uq_dist_lane_route');
                $table->index(['tenant_id', 'destination_warehouse_id'], 'idx_dist_lane_destination');
            });
        }

        if (! Schema::hasTable('distribution_transfer_meta')) {
            Schema::create('distribution_transfer_meta', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('transfer_order_id')->constrained('transfer_orders')->cascadeOnDelete();
                $table->uuid('lane_id')->nullable();
                $table->string('carrier', 120)->nullable();
                $table->string('tracking_number', 120)->nullable();
                $table->string('ddt_reference', 120)->nullable();
                $table->date('expected_arrival_date')->nullable()->index();
                $table->timestamp('dispatched_at')->nullable();
                $this->audit($table);

                $table->unique(['transfer_order_id'], 'uq_dist_transfer_meta_order');
            });
        }

        if (! Schema::hasTable('distribution_replenishment_rules')) {
            Schema::create('distribution_replenishment_rules', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->foreignUuid('stock_item_id')->constrained('stock_items')->cascadeOnDelete();
                $table->string('policy', 40)->default('min_max');
                $table->uuid('source_warehouse_id')->nullable()->index();
                $table->decimal('safety_stock', 18, 4)->default(0);
                $table->decimal('reorder_point', 18, 4)->default(0);
                $table->decimal('max_stock', 18, 4)->default(0);
                $table->decimal('fixed_quantity', 18, 4)->default(0);
                $table->decimal('min_transfer_qty', 18, 4)->default(0);
                $table->decimal('transfer_multiple', 18, 4)->default(0);
                $table->unsignedInteger('lead_time_days')->default(1);
                $table->unsignedInteger('review_cycle_days')->nullable();
                $table->unsignedInteger('target_days_of_supply')->nullable();
                $table->unsignedInteger('priority')->default(100);
                $table->boolean('is_active')->default(true);
                $this->audit($table);

                $table->unique(['tenant_id', 'warehouse_id', 'stock_item_id'], 'uq_dist_rule_warehouse_item');
            });
        }

        if (! Schema::hasTable('distribution_drp_runs')) {
            Schema::create('distribution_drp_runs', function (Blueprint $table): void {
                $this->base($table);
                $table->string('run_number', 40);
                $table->string('bucket_type', 10)->default('weekly');
                $table->unsignedInteger('horizon')->default(8);
                $table->string('status', 20)->default('running')->index();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->json('parameters')->nullable();
                $table->json('summary')->nullable();
                $table->uuid('triggered_by')->nullable();
                $this->audit($table);

                $table->unique(['tenant_id', 'run_number'], 'uq_dist_drp_run_number');
            });
        }

        if (! Schema::hasTable('distribution_drp_plan_lines')) {
            Schema::create('distribution_drp_plan_lines', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('drp_run_id')->constrained('distribution_drp_runs')->cascadeOnDelete();
                $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->foreignUuid('stock_item_id')->constrained('stock_items')->cascadeOnDelete();
                $table->uuid('source_warehouse_id')->nullable();
                $table->unsignedInteger('lead_time_days')->default(0);
                $table->decimal('opening_balance', 18, 4)->default(0);
                $table->decimal('safety_stock', 18, 4)->default(0);
                $table->json('cells');
                $this->audit($table);

                $table->index(['drp_run_id', 'warehouse_id', 'stock_item_id'], 'idx_dist_plan_line_scope');
            });
        }

        if (! Schema::hasTable('distribution_drp_alerts')) {
            Schema::create('distribution_drp_alerts', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('drp_run_id')->constrained('distribution_drp_runs')->cascadeOnDelete();
                $table->string('alert_type', 30)->index();
                $table->string('severity', 15)->default('warning');
                $table->uuid('warehouse_id')->nullable()->index();
                $table->uuid('stock_item_id')->nullable()->index();
                $table->unsignedInteger('bucket_index')->nullable();
                $table->uuid('transfer_order_id')->nullable();
                $table->json('params')->nullable();
                $this->audit($table);
            });
        }

        if (! Schema::hasTable('distribution_replenishment_proposals')) {
            Schema::create('distribution_replenishment_proposals', function (Blueprint $table): void {
                $this->base($table);
                $table->string('proposal_type', 20)->default('replenishment');
                $table->string('status', 20)->default('pending');
                $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->foreignUuid('stock_item_id')->constrained('stock_items')->cascadeOnDelete();
                $table->uuid('source_warehouse_id')->nullable()->index();
                $table->uuid('rule_id')->nullable();
                $table->decimal('suggested_quantity', 18, 4)->default(0);
                $table->decimal('original_quantity', 18, 4)->default(0);
                $table->decimal('shortfall_quantity', 18, 4)->default(0);
                $table->decimal('source_available_quantity', 18, 4)->default(0);
                $table->date('projected_stockout_date')->nullable();
                $table->json('calculation')->nullable();
                $table->uuid('transfer_order_id')->nullable()->index();
                $table->uuid('drp_run_id')->nullable();
                $table->timestamp('generated_at')->nullable();
                $table->uuid('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $this->audit($table);

                $table->index(['tenant_id', 'status', 'proposal_type'], 'idx_dist_proposal_status');
                $table->index(['tenant_id', 'warehouse_id', 'stock_item_id'], 'idx_dist_proposal_scope');
            });
        }

        if (! Schema::hasTable('distribution_sales_channels')) {
            Schema::create('distribution_sales_channels', function (Blueprint $table): void {
                $this->base($table);
                $table->string('code', 40);
                $table->string('name', 160);
                $table->string('channel_type', 30)->default('custom');
                $table->unsignedInteger('priority')->default(100);
                $table->boolean('is_active')->default(true);
                $table->text('description')->nullable();
                $this->audit($table);

                $table->unique(['tenant_id', 'code'], 'uq_dist_channel_code');
            });
        }

        if (! Schema::hasTable('distribution_sales_channel_warehouses')) {
            Schema::create('distribution_sales_channel_warehouses', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('sales_channel_id')->constrained('distribution_sales_channels')->cascadeOnDelete();
                $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->string('allocation_mode', 20)->default('shared_pool');
                $table->decimal('allocation_percentage', 7, 3)->nullable();
                $table->decimal('fixed_reserve_qty', 18, 4)->default(0);
                $table->unsignedInteger('priority_rank')->default(100);
                $table->boolean('include_in_transit')->default(false);
                $table->boolean('exclude_consignment')->default(true);
                $table->boolean('exclude_3pl')->default(false);
                $table->decimal('safety_buffer_qty', 18, 4)->default(0);
                $table->boolean('is_active')->default(true);
                $this->audit($table);

                $table->unique(['tenant_id', 'sales_channel_id', 'warehouse_id'], 'uq_dist_channel_warehouse');
            });
        }

        if (! Schema::hasTable('distribution_consignment_settlements')) {
            Schema::create('distribution_consignment_settlements', function (Blueprint $table): void {
                $this->base($table);
                $table->string('settlement_number', 40);
                $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->string('direction', 5)->default('out');
                $table->string('status', 20)->default('open')->index();
                $table->string('reference', 120)->nullable();
                $table->date('settled_at');
                $table->decimal('total_quantity', 18, 4)->default(0);
                $table->decimal('total_value', 18, 2)->default(0);
                $table->text('notes')->nullable();
                $table->uuid('invoice_document_id')->nullable();
                $this->audit($table);

                $table->unique(['tenant_id', 'settlement_number'], 'uq_dist_settlement_number');
            });
        }

        if (! Schema::hasTable('distribution_consignment_settlement_lines')) {
            Schema::create('distribution_consignment_settlement_lines', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('settlement_id')->constrained('distribution_consignment_settlements')->cascadeOnDelete();
                $table->foreignUuid('stock_item_id')->constrained('stock_items')->cascadeOnDelete();
                $table->decimal('quantity', 18, 4);
                $table->decimal('unit_price', 18, 4)->default(0);
                $table->decimal('line_total', 18, 2)->default(0);
                $this->audit($table);
            });
        }

        if (! Schema::hasTable('distribution_threepl_reconciliations')) {
            Schema::create('distribution_threepl_reconciliations', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->string('external_reference', 120)->nullable();
                $table->string('status', 20)->default('balanced')->index();
                $table->timestamp('reconciled_at')->nullable();
                $table->json('lines');
                $this->audit($table);
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'distribution_threepl_reconciliations',
            'distribution_consignment_settlement_lines',
            'distribution_consignment_settlements',
            'distribution_sales_channel_warehouses',
            'distribution_sales_channels',
            'distribution_replenishment_proposals',
            'distribution_drp_alerts',
            'distribution_drp_plan_lines',
            'distribution_drp_runs',
            'distribution_replenishment_rules',
            'distribution_transfer_meta',
            'distribution_lanes',
            'distribution_warehouse_profiles',
        ] as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
