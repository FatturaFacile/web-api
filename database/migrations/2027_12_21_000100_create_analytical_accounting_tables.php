<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Industrial and analytical cost accounting (Mod 018): hierarchical cost and profit centers, the management ledger
     * kept apart from the statutory general ledger, derivation rules, allocation drivers and the step-down apportionment
     * runs with their full allocation trace. New tables only, dialect-agnostic (SQLite locally, PostgreSQL in production).
     */
    public function up(): void
    {
        $base = static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
        };
        $audit = static function (Blueprint $table): void {
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
        };

        if (! Schema::hasTable('analytical_centers')) {
            Schema::create('analytical_centers', function (Blueprint $table) use ($base, $audit): void {
                $base($table);
                $table->uuid('parent_id')->nullable()->index();
                $table->string('code', 40);
                $table->string('name', 160);
                $table->json('name_translations')->nullable();
                $table->string('center_type', 16);
                $table->string('category', 16);
                $table->string('path', 1000)->default('');
                $table->unsignedSmallInteger('depth')->default(0);
                $table->uuid('manager_user_id')->nullable();
                $table->string('manager_name', 160)->nullable();
                $table->boolean('is_active')->default(true);
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->uuid('default_profit_center_id')->nullable();
                $table->uuid('default_project_id')->nullable();
                $table->uuid('default_product_line_id')->nullable();
                $table->string('default_cost_element_type', 24)->nullable();
                $table->json('metadata')->nullable();
                $audit($table);
                $table->unique(['tenant_id', 'code']);
                $table->index(['tenant_id', 'center_type', 'is_active']);
            });
        }

        if (! Schema::hasTable('analytical_allocation_drivers')) {
            Schema::create('analytical_allocation_drivers', function (Blueprint $table) use ($base, $audit): void {
                $base($table);
                $table->string('code', 40);
                $table->string('name', 160);
                $table->string('driver_type', 24);
                $table->string('unit', 24)->nullable();
                $table->boolean('is_system')->default(false);
                $table->boolean('is_active')->default(true);
                $audit($table);
                $table->unique(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('analytical_driver_values')) {
            Schema::create('analytical_driver_values', function (Blueprint $table) use ($base, $audit): void {
                $base($table);
                $table->foreignUuid('allocation_driver_id')->constrained('analytical_allocation_drivers')->cascadeOnDelete();
                $table->foreignUuid('analytical_center_id')->constrained('analytical_centers')->cascadeOnDelete();
                $table->string('period_key', 7);
                $table->decimal('value', 18, 4)->default(0);
                $audit($table);
                $table->unique(['allocation_driver_id', 'analytical_center_id', 'period_key'], 'uq_analytical_driver_value');
                $table->index(['tenant_id', 'period_key']);
            });
        }

        if (! Schema::hasTable('analytical_derivation_rules')) {
            Schema::create('analytical_derivation_rules', function (Blueprint $table) use ($base, $audit): void {
                $base($table);
                $table->string('name', 160);
                $table->integer('priority')->default(100);
                $table->boolean('is_active')->default(true);
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->uuid('accounting_cause_id')->nullable();
                $table->string('journal_type', 40)->nullable();
                $table->uuid('product_id')->nullable();
                $table->uuid('product_category_id')->nullable();
                $table->uuid('account_id')->nullable();
                $table->string('counterparty_type', 20)->nullable();
                $table->uuid('counterparty_id')->nullable();
                $table->uuid('cost_center_id')->nullable();
                $table->uuid('profit_center_id')->nullable();
                $table->uuid('project_id')->nullable();
                $table->uuid('product_line_id')->nullable();
                $table->string('cost_element_type', 24)->nullable();
                $audit($table);
                $table->index(['tenant_id', 'is_active', 'priority']);
            });
        }

        if (! Schema::hasTable('analytical_entries')) {
            Schema::create('analytical_entries', function (Blueprint $table) use ($base, $audit): void {
                $base($table);
                $table->string('entry_type', 28);
                $table->date('posting_date');
                $table->string('period_key', 7);
                $table->string('currency', 3)->default('EUR');
                $table->string('description', 255)->nullable();
                $table->string('source_type', 40)->nullable();
                $table->uuid('source_id')->nullable();
                $table->uuid('gl_journal_entry_id')->nullable();
                $table->uuid('invoice_id')->nullable();
                $table->uuid('apportionment_run_id')->nullable();
                $table->boolean('is_reversed')->default(false);
                $table->uuid('reversed_by_entry_id')->nullable();
                $audit($table);
                $table->index(['tenant_id', 'period_key', 'entry_type']);
                $table->index(['tenant_id', 'source_type', 'source_id']);
                $table->index('apportionment_run_id');
            });
        }

        if (! Schema::hasTable('analytical_entry_lines')) {
            Schema::create('analytical_entry_lines', function (Blueprint $table) use ($base, $audit): void {
                $base($table);
                $table->foreignUuid('analytical_entry_id')->constrained('analytical_entries')->cascadeOnDelete();
                $table->unsignedInteger('line_number')->default(1);
                $table->uuid('cost_center_id')->nullable()->index();
                $table->uuid('profit_center_id')->nullable()->index();
                $table->uuid('project_id')->nullable()->index();
                $table->uuid('product_line_id')->nullable()->index();
                $table->uuid('department_id')->nullable();
                $table->uuid('location_id')->nullable();
                $table->string('cost_element_type', 24);
                $table->decimal('amount', 18, 4);
                $table->decimal('split_percentage', 9, 4)->nullable();
                $table->date('posting_date');
                $table->string('period_key', 7);
                $table->string('derivation_source', 16)->default('MANUAL');
                $table->json('derivation_meta')->nullable();
                $table->uuid('gl_journal_line_id')->nullable();
                $table->uuid('invoice_line_id')->nullable();
                $table->uuid('apportionment_step_id')->nullable();
                $table->string('description', 255)->nullable();
                $audit($table);
                $table->index(['tenant_id', 'period_key', 'cost_element_type']);
            });
        }

        if (! Schema::hasTable('apportionment_schedules')) {
            Schema::create('apportionment_schedules', function (Blueprint $table) use ($base, $audit): void {
                $base($table);
                $table->string('name', 160);
                $table->boolean('is_active')->default(true);
                $table->text('description')->nullable();
                $audit($table);
                $table->unique(['tenant_id', 'name']);
            });
        }

        if (! Schema::hasTable('apportionment_schedule_steps')) {
            Schema::create('apportionment_schedule_steps', function (Blueprint $table) use ($base, $audit): void {
                $base($table);
                $table->foreignUuid('apportionment_schedule_id')->constrained('apportionment_schedules')->cascadeOnDelete();
                $table->unsignedInteger('step_order');
                $table->foreignUuid('source_center_id')->constrained('analytical_centers')->restrictOnDelete();
                $table->foreignUuid('allocation_driver_id')->nullable()->constrained('analytical_allocation_drivers')->nullOnDelete();
                $table->string('driver_type', 24);
                $table->boolean('include_descendants')->default(false);
                $table->json('fixed_weights_json')->nullable();
                $table->json('target_filter_json')->nullable();
                $audit($table);
                $table->unique(['apportionment_schedule_id', 'step_order'], 'uq_apportionment_step_order');
            });
        }

        if (! Schema::hasTable('apportionment_runs')) {
            Schema::create('apportionment_runs', function (Blueprint $table) use ($base, $audit): void {
                $base($table);
                $table->uuid('apportionment_schedule_id')->nullable()->index();
                $table->string('period_key', 7);
                $table->date('period_start');
                $table->date('period_end');
                $table->string('status', 16);
                $table->decimal('total_pool', 18, 4)->default(0);
                $table->decimal('total_allocated', 18, 4)->default(0);
                $table->decimal('total_unallocated', 18, 4)->default(0);
                $table->json('warnings')->nullable();
                $table->timestamp('consolidated_at')->nullable();
                $table->uuid('consolidated_by')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->uuid('reversed_by')->nullable();
                $table->string('reversal_reason', 255)->nullable();
                $audit($table);
                $table->index(['tenant_id', 'period_key', 'status']);
            });
        }

        if (! Schema::hasTable('apportionment_run_steps')) {
            Schema::create('apportionment_run_steps', function (Blueprint $table) use ($base, $audit): void {
                $base($table);
                $table->foreignUuid('apportionment_run_id')->constrained('apportionment_runs')->cascadeOnDelete();
                $table->unsignedInteger('step_order');
                $table->uuid('source_center_id')->index();
                $table->string('driver_type', 24);
                $table->string('status', 16);
                $table->decimal('pool_amount', 18, 4)->default(0);
                $table->decimal('allocated_amount', 18, 4)->default(0);
                $table->decimal('driver_total', 18, 4)->default(0);
                $table->json('warnings')->nullable();
                $audit($table);
                $table->unique(['apportionment_run_id', 'step_order'], 'uq_run_step_order');
            });
        }

        if (! Schema::hasTable('apportionment_run_allocations')) {
            Schema::create('apportionment_run_allocations', function (Blueprint $table) use ($base, $audit): void {
                $base($table);
                $table->foreignUuid('apportionment_run_id')->constrained('apportionment_runs')->cascadeOnDelete();
                $table->unsignedInteger('step_order');
                $table->uuid('source_center_id')->index();
                $table->uuid('target_center_id')->index();
                $table->string('driver_type', 24);
                $table->decimal('driver_value', 18, 4)->default(0);
                $table->decimal('driver_total', 18, 4)->default(0);
                $table->decimal('share_ratio', 12, 8)->default(0);
                $table->decimal('allocated_amount', 18, 4)->default(0);
                $table->decimal('rounding_adjustment', 18, 4)->default(0);
                $table->uuid('analytical_entry_id')->nullable();
                $audit($table);
                $table->index(['apportionment_run_id', 'step_order']);
            });
        }
    }

    public function down(): void
    {
        foreach (['apportionment_run_allocations', 'apportionment_run_steps', 'apportionment_runs', 'apportionment_schedule_steps', 'apportionment_schedules', 'analytical_entry_lines', 'analytical_entries', 'analytical_derivation_rules', 'analytical_driver_values', 'analytical_allocation_drivers', 'analytical_centers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
