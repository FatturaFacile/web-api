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
        // 1. Asset Transfers (Location, Custodian, Project & Inter-Department Transfers)
        if (! Schema::hasTable('eam_asset_transfers')) {
            Schema::create('eam_asset_transfers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('asset_id')->constrained('eam_fixed_assets')->cascadeOnDelete();
                $table->string('transfer_number', 60)->index();
                $table->foreignUuid('from_location_id')->nullable()->constrained('eam_asset_locations')->nullOnDelete();
                $table->foreignUuid('to_location_id')->nullable()->constrained('eam_asset_locations')->nullOnDelete();
                $table->foreignUuid('from_custodian_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUuid('to_custodian_id')->nullable()->constrained('users')->nullOnDelete();
                $table->uuid('from_project_id')->nullable()->index();
                $table->uuid('to_project_id')->nullable()->index();
                $table->date('transfer_date');
                $table->string('status', 40)->default('pending')->index(); // pending, pending_approval, approved, completed, rejected
                $table->uuid('workflow_instance_id')->nullable()->index();
                $table->boolean('is_locked')->default(false);
                $table->text('reason')->nullable();
                $table->foreignUuid('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('completed_at')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'transfer_number']);
            });
        }

        // 2. Depreciation Runs (Batch Monthly & Yearly Execution Header)
        if (! Schema::hasTable('eam_depreciation_runs')) {
            Schema::create('eam_depreciation_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('run_number', 60)->index();
                $table->string('period_code', 20)->index(); // e.g., '2026-10' or '2026'
                $table->string('period_type', 20)->default('monthly'); // monthly, yearly
                $table->string('ledger_type', 30)->default('statutory'); // statutory, ifrs, tax, management
                $table->unsignedInteger('assets_processed_count')->default(0);
                $table->decimal('total_depreciation_amount', 15, 2)->default(0.00);
                $table->string('status', 40)->default('calculated')->index(); // running, calculated, posted, reversed, failed
                $table->uuid('fiscal_period_id')->nullable()->index();
                $table->foreignUuid('executed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('executed_at')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->jsonb('summary_metrics')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'period_code', 'period_type', 'ledger_type'], 'eam_depr_runs_period_unique');
            });
        }

        // 3. Depreciation Schedules & Entries (Asset-Level Period Calculations)
        if (! Schema::hasTable('eam_depreciation_schedules')) {
            Schema::create('eam_depreciation_schedules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('asset_id')->constrained('eam_fixed_assets')->cascadeOnDelete();
                $table->foreignUuid('run_id')->nullable()->constrained('eam_depreciation_runs')->nullOnDelete();
                $table->string('ledger_type', 30)->default('statutory')->index();
                $table->string('period_code', 20)->index();
                $table->string('period_type', 20)->default('monthly');
                $table->string('depreciation_method', 50);
                $table->decimal('opening_book_value', 15, 2);
                $table->decimal('depreciation_amount', 15, 2);
                $table->decimal('closing_book_value', 15, 2);
                $table->decimal('accumulated_depreciation', 15, 2);
                $table->decimal('units_produced', 15, 2)->default(0.00);
                $table->string('status', 30)->default('calculated')->index(); // calculated, posted, reversed
                $table->uuid('journal_entry_id')->nullable()->index();
                $table->timestamp('calculated_at')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->jsonb('calculation_snapshot')->nullable();
                $table->timestamps();
                $table->softDeletes();

                // Concurrency & Idempotency Guard: Prevent Duplicate Depreciation for same asset/ledger/period
                $table->unique(['tenant_id', 'asset_id', 'ledger_type', 'period_code'], 'eam_depr_sched_idempotent_unique');
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE eam_depreciation_schedules ADD CONSTRAINT chk_eam_depr_amounts CHECK (depreciation_amount >= 0 AND closing_book_value >= 0)');
            }
        }

        // 4. Asset Valuations (IAS 16 Revaluations & IAS 36 Impairments)
        if (! Schema::hasTable('eam_asset_valuations')) {
            Schema::create('eam_asset_valuations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('asset_id')->constrained('eam_fixed_assets')->cascadeOnDelete();
                $table->string('valuation_number', 60)->index();
                $table->string('valuation_type', 30)->index(); // revaluation, impairment
                $table->date('valuation_date');
                $table->decimal('carrying_amount_before', 15, 2);
                $table->decimal('fair_value', 15, 2)->default(0.00);
                $table->decimal('value_in_use', 15, 2)->default(0.00);
                $table->decimal('recoverable_amount', 15, 2);
                $table->decimal('adjustment_amount', 15, 2); // positive for revaluation surplus, negative for impairment loss
                $table->decimal('revaluation_reserve_delta', 15, 2)->default(0.00);
                $table->decimal('impairment_loss_amount', 15, 2)->default(0.00);
                $table->decimal('carrying_amount_after', 15, 2);
                $table->string('status', 40)->default('pending')->index(); // pending_approval, approved, posted, rejected
                $table->uuid('workflow_instance_id')->nullable()->index();
                $table->uuid('journal_entry_id')->nullable()->index();
                $table->boolean('is_locked')->default(false);
                $table->string('appraiser_name', 150)->nullable();
                $table->text('reason')->nullable();
                $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'valuation_number']);
            });
        }

        // 5. Asset Disposals (Retirement, Sale, Scrap, Loss)
        if (! Schema::hasTable('eam_asset_disposals')) {
            Schema::create('eam_asset_disposals', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('asset_id')->constrained('eam_fixed_assets')->cascadeOnDelete();
                $table->string('disposal_number', 60)->index();
                $table->string('disposal_type', 40)->index(); // sale, scrap, retirement, donation, theft_loss
                $table->date('disposal_date');
                $table->decimal('asset_cost_at_disposal', 15, 2);
                $table->decimal('accumulated_depreciation_at_disposal', 15, 2);
                $table->decimal('carrying_amount_at_disposal', 15, 2);
                $table->decimal('proceeds_amount', 15, 2)->default(0.00);
                $table->decimal('disposal_costs', 15, 2)->default(0.00);
                $table->decimal('gain_loss_amount', 15, 2)->default(0.00); // proceeds - disposal_costs - carrying_amount
                $table->uuid('buyer_contact_id')->nullable()->index();
                $table->string('status', 40)->default('pending')->index(); // pending_approval, approved, completed, rejected
                $table->uuid('workflow_instance_id')->nullable()->index();
                $table->uuid('journal_entry_id')->nullable()->index();
                $table->boolean('is_locked')->default(false);
                $table->text('reason')->nullable();
                $table->foreignUuid('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('completed_at')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'disposal_number']);
            });
        }

        // 6. Maintenance Plans (Preventive, Condition-Based & Calibration Schedules)
        if (! Schema::hasTable('eam_maintenance_plans')) {
            Schema::create('eam_maintenance_plans', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('asset_id')->nullable()->constrained('eam_fixed_assets')->cascadeOnDelete();
                $table->foreignUuid('asset_class_id')->nullable()->constrained('eam_asset_classes')->nullOnDelete();
                $table->string('plan_code', 60)->index();
                $table->string('name', 200);
                $table->string('maintenance_type', 40)->default('preventive')->index(); // preventive, corrective, condition_based, predictive, calibration
                $table->unsignedInteger('frequency_days')->default(90);
                $table->decimal('meter_interval_units', 12, 2)->nullable();
                $table->decimal('estimated_duration_hours', 8, 2)->default(2.00);
                $table->decimal('estimated_cost', 15, 2)->default(0.00);
                $table->date('last_executed_date')->nullable();
                $table->date('next_due_date')->nullable()->index();
                $table->boolean('is_active')->default(true);
                $table->jsonb('checklist')->nullable();
                $table->jsonb('translations')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'plan_code']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX eam_maintenance_plans_checklist_gin ON eam_maintenance_plans USING GIN (checklist)');
            }
        }

        // 7. Maintenance Work Orders (Execution, Costs, Capitalization & Downtime Tracking)
        if (! Schema::hasTable('eam_maintenance_work_orders')) {
            Schema::create('eam_maintenance_work_orders', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('asset_id')->constrained('eam_fixed_assets')->cascadeOnDelete();
                $table->foreignUuid('plan_id')->nullable()->constrained('eam_maintenance_plans')->nullOnDelete();
                $table->string('work_order_number', 60)->index();
                $table->string('maintenance_type', 40)->default('preventive')->index(); // preventive, corrective, condition_based, emergency, upgrade
                $table->string('priority', 30)->default('medium')->index(); // low, medium, high, critical
                $table->string('status', 40)->default('scheduled')->index(); // scheduled, in_progress, pending_approval, completed, cancelled
                $table->date('scheduled_date');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->decimal('downtime_hours', 10, 2)->default(0.00);
                $table->decimal('labor_cost', 15, 2)->default(0.00);
                $table->decimal('parts_cost', 15, 2)->default(0.00);
                $table->decimal('external_service_cost', 15, 2)->default(0.00);
                $table->decimal('total_cost', 15, 2)->default(0.00);
                $table->boolean('capitalize_as_upgrade')->default(false);
                $table->string('condition_after_maintenance', 40)->nullable();
                $table->foreignUuid('assigned_technician_id')->nullable()->constrained('users')->nullOnDelete();
                $table->uuid('workflow_instance_id')->nullable()->index();
                $table->boolean('is_locked')->default(false);
                $table->text('problem_description')->nullable();
                $table->text('resolution_notes')->nullable();
                $table->jsonb('parts_consumed')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'work_order_number']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE eam_maintenance_work_orders ADD CONSTRAINT chk_eam_mwo_costs CHECK (labor_cost >= 0 AND parts_cost >= 0 AND external_service_cost >= 0 AND total_cost >= 0 AND downtime_hours >= 0)');
            }
        }

        // 8. Project Asset Allocations (Module 15 Equipment Allocation, Utilization & Cost Attribution)
        if (! Schema::hasTable('eam_project_asset_allocations')) {
            Schema::create('eam_project_asset_allocations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('asset_id')->constrained('eam_fixed_assets')->cascadeOnDelete();
                $table->uuid('project_id')->index();
                $table->date('allocated_from');
                $table->date('allocated_to')->nullable();
                $table->decimal('hourly_rate', 12, 2)->default(0.00);
                $table->decimal('daily_rate', 12, 2)->default(0.00);
                $table->decimal('utilized_hours', 12, 2)->default(0.00);
                $table->decimal('utilized_days', 12, 2)->default(0.00);
                $table->decimal('attributed_cost', 15, 2)->default(0.00);
                $table->string('status', 30)->default('active')->index(); // allocated, active, released
                $table->foreignUuid('allocated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('released_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        // Non-destructive enterprise migration policy
    }
};
