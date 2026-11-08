<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise extension of the manufacturing module (additive only, safe for `migrate --force`).
 *
 * - Items: catalog product link, procurement type, default warehouse, lot size multiple and the standard cost
 *   breakdown computed by the cost rollup (material, labour, overhead, subcontracting).
 * - BOM revisions with effective dates, approval and the engineering change order that produced them; issue method
 *   (manual or backflush) and operation of each component.
 * - Work centre weekly calendars (`mfg_work_center_shifts`) and dated exceptions (`mfg_work_center_capacities`).
 * - Subcontracted operations, production order execution data (reservations, standard and actual costs, variances,
 *   WIP postings), operation confirmations, material movements, subcontract orders, demand forecasts and the ledger
 *   accounts of the module (`mfg_accounting_settings`).
 * - Quality inspection points with accepted and rejected quantities, non-conformance quantities.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->extendMasterData();
        $this->extendExecution();
        $this->createExecutionTables();
        $this->extendPlanningAndQuality();
    }

    public function down(): void
    {
        // Non-destructive enterprise migration policy: the added columns and tables hold production and ledger history.
    }

    private function extendMasterData(): void
    {
        Schema::table('mfg_items', function (Blueprint $table): void {
            $this->addColumn($table, 'mfg_items', 'product_id', fn () => $table->uuid('product_id')->nullable()->index());
            $this->addColumn($table, 'mfg_items', 'procurement_type', fn () => $table->string('procurement_type', 20)->nullable());
            $this->addColumn($table, 'mfg_items', 'default_warehouse_id', fn () => $table->uuid('default_warehouse_id')->nullable());
            $this->addColumn($table, 'mfg_items', 'lot_size_multiple', fn () => $table->decimal('lot_size_multiple', 15, 4)->nullable());
            $this->addColumn($table, 'mfg_items', 'standard_material_cost', fn () => $table->decimal('standard_material_cost', 15, 4)->default(0));
            $this->addColumn($table, 'mfg_items', 'standard_labor_cost', fn () => $table->decimal('standard_labor_cost', 15, 4)->default(0));
            $this->addColumn($table, 'mfg_items', 'standard_overhead_cost', fn () => $table->decimal('standard_overhead_cost', 15, 4)->default(0));
            $this->addColumn($table, 'mfg_items', 'standard_subcontract_cost', fn () => $table->decimal('standard_subcontract_cost', 15, 4)->default(0));
            $this->addColumn($table, 'mfg_items', 'standard_cost_rolled_up_at', fn () => $table->timestamp('standard_cost_rolled_up_at')->nullable());
        });

        Schema::table('mfg_boms', function (Blueprint $table): void {
            $this->addColumn($table, 'mfg_boms', 'approved_by', fn () => $table->uuid('approved_by')->nullable());
            $this->addColumn($table, 'mfg_boms', 'approved_at', fn () => $table->timestamp('approved_at')->nullable());
            $this->addColumn($table, 'mfg_boms', 'supersedes_bom_id', fn () => $table->uuid('supersedes_bom_id')->nullable()->index());
            $this->addColumn($table, 'mfg_boms', 'engineering_change_order_id', fn () => $table->uuid('engineering_change_order_id')->nullable()->index());
        });

        Schema::table('mfg_bom_components', function (Blueprint $table): void {
            $this->addColumn($table, 'mfg_bom_components', 'issue_method', fn () => $table->string('issue_method', 20)->default('manual'));
            $this->addColumn($table, 'mfg_bom_components', 'operation_sequence', fn () => $table->integer('operation_sequence')->nullable());
        });

        Schema::table('mfg_engineering_change_orders', function (Blueprint $table): void {
            $this->addColumn($table, 'mfg_engineering_change_orders', 'bom_id', fn () => $table->uuid('bom_id')->nullable()->index());
            $this->addColumn($table, 'mfg_engineering_change_orders', 'resulting_bom_id', fn () => $table->uuid('resulting_bom_id')->nullable());
            $this->addColumn($table, 'mfg_engineering_change_orders', 'submitted_at', fn () => $table->timestamp('submitted_at')->nullable());
            $this->addColumn($table, 'mfg_engineering_change_orders', 'rejected_by', fn () => $table->uuid('rejected_by')->nullable());
            $this->addColumn($table, 'mfg_engineering_change_orders', 'rejected_at', fn () => $table->timestamp('rejected_at')->nullable());
            $this->addColumn($table, 'mfg_engineering_change_orders', 'rejection_reason', fn () => $table->text('rejection_reason')->nullable());
        });

        Schema::table('mfg_routings', function (Blueprint $table): void {
            $this->addColumn($table, 'mfg_routings', 'effective_from', fn () => $table->date('effective_from')->nullable());
            $this->addColumn($table, 'mfg_routings', 'effective_to', fn () => $table->date('effective_to')->nullable());
        });

        Schema::table('mfg_operations', function (Blueprint $table): void {
            $this->addColumn($table, 'mfg_operations', 'description', fn () => $table->text('description')->nullable());
            $this->addColumn($table, 'mfg_operations', 'is_subcontracted', fn () => $table->boolean('is_subcontracted')->default(false));
            $this->addColumn($table, 'mfg_operations', 'subcontractor_contact_id', fn () => $table->uuid('subcontractor_contact_id')->nullable());
            $this->addColumn($table, 'mfg_operations', 'subcontract_unit_cost', fn () => $table->decimal('subcontract_unit_cost', 15, 4)->default(0));
            $this->addColumn($table, 'mfg_operations', 'quality_plan_id', fn () => $table->uuid('quality_plan_id')->nullable());
        });

        Schema::table('mfg_work_center_capacities', function (Blueprint $table): void {
            $this->addColumn($table, 'mfg_work_center_capacities', 'reason', fn () => $table->string('reason', 255)->nullable());
        });

        if (! Schema::hasTable('mfg_work_center_shifts')) {
            Schema::create('mfg_work_center_shifts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('work_center_id');
                $table->unsignedSmallInteger('weekday');
                $table->decimal('available_hours', 5, 2)->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('work_center_id')->references('id')->on('mfg_work_centers')->cascadeOnDelete();
                $table->unique(['tenant_id', 'work_center_id', 'weekday']);
            });
        }
    }

    private function extendExecution(): void
    {
        Schema::table('mfg_production_orders', function (Blueprint $table): void {
            $this->addColumn($table, 'mfg_production_orders', 'warehouse_id', fn () => $table->uuid('warehouse_id')->nullable());
            $this->addColumn($table, 'mfg_production_orders', 'receipt_warehouse_id', fn () => $table->uuid('receipt_warehouse_id')->nullable());
            $this->addColumn($table, 'mfg_production_orders', 'planned_order_id', fn () => $table->uuid('planned_order_id')->nullable()->index());
            $this->addColumn($table, 'mfg_production_orders', 'sales_order_id', fn () => $table->uuid('sales_order_id')->nullable()->index());
            $this->addColumn($table, 'mfg_production_orders', 'created_by', fn () => $table->uuid('created_by')->nullable());
            $this->addColumn($table, 'mfg_production_orders', 'released_by', fn () => $table->uuid('released_by')->nullable());
            $this->addColumn($table, 'mfg_production_orders', 'released_at', fn () => $table->timestamp('released_at')->nullable());
            $this->addColumn($table, 'mfg_production_orders', 'closed_by', fn () => $table->uuid('closed_by')->nullable());
            $this->addColumn($table, 'mfg_production_orders', 'closed_at', fn () => $table->timestamp('closed_at')->nullable());
            foreach (['std_unit_material_cost', 'std_unit_labor_cost', 'std_unit_overhead_cost', 'std_unit_subcontract_cost'] as $column) {
                $this->addColumn($table, 'mfg_production_orders', $column, fn () => $table->decimal($column, 15, 6)->default(0));
            }
            foreach (['actual_material_cost', 'actual_labor_cost', 'actual_overhead_cost', 'actual_subcontract_cost', 'received_value', 'material_variance', 'labor_variance', 'overhead_variance', 'subcontract_variance'] as $column) {
                $this->addColumn($table, 'mfg_production_orders', $column, fn () => $table->decimal($column, 15, 4)->default(0));
            }
            $this->addColumn($table, 'mfg_production_orders', 'variance_journal_entry_id', fn () => $table->uuid('variance_journal_entry_id')->nullable());
        });

        Schema::table('mfg_production_order_lines', function (Blueprint $table): void {
            $this->addColumn($table, 'mfg_production_order_lines', 'bom_component_id', fn () => $table->uuid('bom_component_id')->nullable());
            $this->addColumn($table, 'mfg_production_order_lines', 'quantity_per_unit', fn () => $table->decimal('quantity_per_unit', 15, 6)->default(0));
            $this->addColumn($table, 'mfg_production_order_lines', 'issue_method', fn () => $table->string('issue_method', 20)->default('manual'));
            $this->addColumn($table, 'mfg_production_order_lines', 'operation_sequence', fn () => $table->integer('operation_sequence')->nullable());
            $this->addColumn($table, 'mfg_production_order_lines', 'reservation_id', fn () => $table->uuid('reservation_id')->nullable());
            $this->addColumn($table, 'mfg_production_order_lines', 'warehouse_id', fn () => $table->uuid('warehouse_id')->nullable());
            $this->addColumn($table, 'mfg_production_order_lines', 'issued_cost', fn () => $table->decimal('issued_cost', 15, 4)->default(0));
        });

        Schema::table('mfg_production_order_operations', function (Blueprint $table): void {
            $this->addColumn($table, 'mfg_production_order_operations', 'operation_code', fn () => $table->string('operation_code', 50)->nullable());
            $this->addColumn($table, 'mfg_production_order_operations', 'name', fn () => $table->string('name', 200)->nullable());
            $this->addColumn($table, 'mfg_production_order_operations', 'planned_labor_minutes', fn () => $table->decimal('planned_labor_minutes', 12, 2)->default(0));
            $this->addColumn($table, 'mfg_production_order_operations', 'actual_labor_minutes', fn () => $table->decimal('actual_labor_minutes', 12, 2)->default(0));
            $this->addColumn($table, 'mfg_production_order_operations', 'labor_rate', fn () => $table->decimal('labor_rate', 15, 4)->default(0));
            $this->addColumn($table, 'mfg_production_order_operations', 'overhead_rate', fn () => $table->decimal('overhead_rate', 15, 4)->default(0));
            $this->addColumn($table, 'mfg_production_order_operations', 'actual_labor_cost', fn () => $table->decimal('actual_labor_cost', 15, 4)->default(0));
            $this->addColumn($table, 'mfg_production_order_operations', 'actual_overhead_cost', fn () => $table->decimal('actual_overhead_cost', 15, 4)->default(0));
            $this->addColumn($table, 'mfg_production_order_operations', 'is_subcontracted', fn () => $table->boolean('is_subcontracted')->default(false));
            $this->addColumn($table, 'mfg_production_order_operations', 'subcontract_unit_cost', fn () => $table->decimal('subcontract_unit_cost', 15, 4)->default(0));
            $this->addColumn($table, 'mfg_production_order_operations', 'scheduled_date', fn () => $table->date('scheduled_date')->nullable());
            $this->addColumn($table, 'mfg_production_order_operations', 'started_at', fn () => $table->timestamp('started_at')->nullable());
            $this->addColumn($table, 'mfg_production_order_operations', 'completed_at', fn () => $table->timestamp('completed_at')->nullable());
        });

        Schema::table('mfg_shop_floor_executions', function (Blueprint $table): void {
            $this->addColumn($table, 'mfg_shop_floor_executions', 'downtime_minutes', fn () => $table->decimal('downtime_minutes', 12, 2)->default(0));
            $this->addColumn($table, 'mfg_shop_floor_executions', 'confirmation_id', fn () => $table->uuid('confirmation_id')->nullable());
        });
    }

    private function createExecutionTables(): void
    {
        if (! Schema::hasTable('mfg_operation_confirmations')) {
            Schema::create('mfg_operation_confirmations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('confirmation_number', 50);
                $table->uuid('production_order_id')->index();
                $table->uuid('production_order_operation_id')->index();
                $table->uuid('shop_floor_execution_id')->nullable();
                $table->uuid('subcontract_order_id')->nullable();
                $table->decimal('setup_minutes', 12, 2)->default(0);
                $table->decimal('run_minutes', 12, 2)->default(0);
                $table->decimal('labor_minutes', 12, 2)->default(0);
                $table->decimal('downtime_minutes', 12, 2)->default(0);
                $table->decimal('good_quantity', 15, 4)->default(0);
                $table->decimal('scrap_quantity', 15, 4)->default(0);
                $table->string('scrap_reason', 255)->nullable();
                $table->boolean('is_final')->default(false);
                $table->decimal('labor_cost', 15, 4)->default(0);
                $table->decimal('overhead_cost', 15, 4)->default(0);
                $table->decimal('subcontract_cost', 15, 4)->default(0);
                $table->date('posting_date');
                $table->uuid('journal_entry_id')->nullable();
                $table->uuid('confirmed_by')->nullable();
                $table->timestamp('confirmed_at');
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('production_order_id')->references('id')->on('mfg_production_orders')->cascadeOnDelete();
                $table->unique(['tenant_id', 'confirmation_number']);
            });
        }

        if (! Schema::hasTable('mfg_material_movements')) {
            Schema::create('mfg_material_movements', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('production_order_id')->index();
                $table->uuid('production_order_line_id')->nullable()->index();
                $table->uuid('subcontract_order_id')->nullable()->index();
                $table->string('movement_type', 30)->index();
                $table->uuid('mfg_item_id')->index();
                $table->uuid('warehouse_id')->nullable();
                $table->decimal('quantity', 15, 4);
                $table->decimal('unit_cost', 15, 6)->default(0);
                $table->decimal('total_cost', 15, 4)->default(0);
                $table->uuid('stock_movement_id')->nullable()->index();
                $table->uuid('lot_id')->nullable();
                $table->json('serial_number_ids')->nullable();
                $table->date('posting_date');
                $table->uuid('journal_entry_id')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('production_order_id')->references('id')->on('mfg_production_orders')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('mfg_subcontract_orders')) {
            Schema::create('mfg_subcontract_orders', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('subcontract_number', 50);
                $table->uuid('production_order_id')->index();
                $table->uuid('production_order_operation_id')->index();
                $table->uuid('subcontractor_contact_id')->index();
                $table->uuid('source_warehouse_id');
                $table->uuid('subcontract_warehouse_id');
                $table->string('status', 30)->default('sent')->index();
                $table->decimal('quantity', 15, 4);
                $table->decimal('received_quantity', 15, 4)->default(0);
                $table->decimal('scrapped_quantity', 15, 4)->default(0);
                $table->decimal('unit_cost', 15, 4)->default(0);
                $table->decimal('total_cost', 15, 4)->default(0);
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->text('notes')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('production_order_id')->references('id')->on('mfg_production_orders')->cascadeOnDelete();
                $table->unique(['tenant_id', 'subcontract_number']);
            });
        }

        if (! Schema::hasTable('mfg_subcontract_order_lines')) {
            Schema::create('mfg_subcontract_order_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('subcontract_order_id')->index();
                $table->uuid('production_order_line_id')->index();
                $table->uuid('mfg_item_id');
                $table->decimal('quantity_sent', 15, 4)->default(0);
                $table->decimal('quantity_consumed', 15, 4)->default(0);
                $table->decimal('quantity_returned', 15, 4)->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('subcontract_order_id')->references('id')->on('mfg_subcontract_orders')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('mfg_accounting_settings')) {
            Schema::create('mfg_accounting_settings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->unique();
                foreach (['raw_material', 'wip', 'finished_goods', 'labor_absorbed', 'overhead_absorbed', 'subcontract_accrual', 'material_variance', 'labor_variance', 'overhead_variance', 'subcontract_variance'] as $account) {
                    $table->string($account.'_account_code', 50)->nullable();
                }
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    private function extendPlanningAndQuality(): void
    {
        if (! Schema::hasTable('mfg_demand_forecasts')) {
            Schema::create('mfg_demand_forecasts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('mfg_item_id');
                $table->date('forecast_date');
                $table->decimal('quantity', 15, 4);
                $table->text('notes')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'mfg_item_id', 'forecast_date']);
            });
        }

        Schema::table('mfg_mrp_planned_orders', function (Blueprint $table): void {
            $this->addColumn($table, 'mfg_mrp_planned_orders', 'net_requirement', fn () => $table->decimal('net_requirement', 15, 4)->default(0));
            $this->addColumn($table, 'mfg_mrp_planned_orders', 'bom_level', fn () => $table->unsignedSmallInteger('bom_level')->default(0));
            $this->addColumn($table, 'mfg_mrp_planned_orders', 'procurement_document_type', fn () => $table->string('procurement_document_type', 100)->nullable());
            $this->addColumn($table, 'mfg_mrp_planned_orders', 'procurement_document_id', fn () => $table->uuid('procurement_document_id')->nullable());
            $this->addColumn($table, 'mfg_mrp_planned_orders', 'converted_by', fn () => $table->uuid('converted_by')->nullable());
            $this->addColumn($table, 'mfg_mrp_planned_orders', 'converted_at', fn () => $table->timestamp('converted_at')->nullable());
        });

        Schema::table('mfg_quality_plans', function (Blueprint $table): void {
            $this->addColumn($table, 'mfg_quality_plans', 'acceptance_defect_limit', fn () => $table->unsignedInteger('acceptance_defect_limit')->default(0));
        });

        Schema::table('mfg_quality_inspections', function (Blueprint $table): void {
            $this->addColumn($table, 'mfg_quality_inspections', 'inspection_point', fn () => $table->string('inspection_point', 20)->default('final'));
            $this->addColumn($table, 'mfg_quality_inspections', 'production_order_operation_id', fn () => $table->uuid('production_order_operation_id')->nullable());
            $this->addColumn($table, 'mfg_quality_inspections', 'material_movement_id', fn () => $table->uuid('material_movement_id')->nullable());
            $this->addColumn($table, 'mfg_quality_inspections', 'mfg_item_id', fn () => $table->uuid('mfg_item_id')->nullable());
            $this->addColumn($table, 'mfg_quality_inspections', 'quantity_inspected', fn () => $table->decimal('quantity_inspected', 15, 4)->default(0));
            $this->addColumn($table, 'mfg_quality_inspections', 'quantity_accepted', fn () => $table->decimal('quantity_accepted', 15, 4)->default(0));
            $this->addColumn($table, 'mfg_quality_inspections', 'quantity_rejected', fn () => $table->decimal('quantity_rejected', 15, 4)->default(0));
        });

        Schema::table('mfg_quality_non_conformances', function (Blueprint $table): void {
            $this->addColumn($table, 'mfg_quality_non_conformances', 'mfg_item_id', fn () => $table->uuid('mfg_item_id')->nullable());
            $this->addColumn($table, 'mfg_quality_non_conformances', 'production_order_operation_id', fn () => $table->uuid('production_order_operation_id')->nullable());
            $this->addColumn($table, 'mfg_quality_non_conformances', 'quantity', fn () => $table->decimal('quantity', 15, 4)->default(0));
        });
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
