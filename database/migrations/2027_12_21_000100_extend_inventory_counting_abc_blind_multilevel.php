<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical inventory and cycle counting (Mod 058, #159): per-tenant count policy (ABC shares and cadences, recount and
 * two-level approval thresholds, GL accounts), history of ABC runs with manual overrides, blind count sessions with
 * per-count entries (idempotent offline sync), the two-level approval trail and the audit log of a count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_count_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->unique();
            $table->uuid('company_id')->nullable();
            $table->decimal('abc_a_share', 5, 2)->default('80.00');
            $table->decimal('abc_b_share', 5, 2)->default('95.00');
            $table->unsignedSmallInteger('abc_period_days')->default(365);
            $table->unsignedSmallInteger('interval_days_a')->default(30);
            $table->unsignedSmallInteger('interval_days_b')->default(90);
            $table->unsignedSmallInteger('interval_days_c')->default(180);
            $table->string('new_item_class', 1)->default('C');
            $table->boolean('blind_by_default')->default(true);
            $table->boolean('reveal_first_count_on_recount')->default(false);
            $table->decimal('recount_threshold_percent', 8, 2)->nullable();
            $table->decimal('recount_threshold_value', 14, 2)->nullable();
            $table->unsignedTinyInteger('max_recount_rounds')->default(2);
            $table->string('approval_mode', 20)->default('workflow');
            $table->boolean('auto_approve_within_tolerance')->default(true);
            $table->decimal('level1_threshold_percent', 8, 2)->nullable();
            $table->decimal('level1_threshold_value', 14, 2)->nullable();
            $table->decimal('level2_threshold_percent', 8, 2)->nullable();
            $table->decimal('level2_threshold_value', 14, 2)->nullable();
            $table->boolean('enforce_segregation_of_duties')->default(true);
            $table->string('inventory_account_code', 20)->default('1200');
            $table->string('gain_account_code', 20)->default('4910');
            $table->string('loss_account_code', 20)->default('5100');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inventory_abc_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('company_id')->nullable();
            $table->uuid('warehouse_id')->nullable()->index();
            $table->unsignedSmallInteger('period_days');
            $table->decimal('a_share', 5, 2);
            $table->decimal('b_share', 5, 2);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->decimal('total_consumption_value', 18, 2)->default('0');
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('class_a_count')->default(0);
            $table->unsignedInteger('class_b_count')->default(0);
            $table->unsignedInteger('class_c_count')->default(0);
            $table->boolean('applied')->default(true);
            $table->uuid('run_by')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inventory_abc_run_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('inventory_abc_run_id');
            $table->uuid('stock_item_id');
            $table->unsignedInteger('rank');
            $table->decimal('consumption_value', 18, 2)->default('0');
            $table->decimal('cumulative_share', 7, 2)->default('0');
            $table->string('abc_class', 1);
            $table->string('previous_class', 1)->nullable();
            $table->boolean('is_manual_override')->default(false);
            $table->boolean('is_new_item')->default(false);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('inventory_abc_run_id')->references('id')->on('inventory_abc_runs')->cascadeOnDelete();
            $table->foreign('stock_item_id')->references('id')->on('stock_items')->cascadeOnDelete();
            $table->index(['inventory_abc_run_id', 'abc_class'], 'idx_abc_run_items_class');
        });

        Schema::create('inventory_abc_overrides', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('stock_item_id');
            $table->string('previous_class', 1)->nullable();
            $table->string('new_class', 1)->nullable();
            $table->string('reason', 500);
            $table->uuid('set_by');
            $table->timestamp('set_at');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('stock_item_id')->references('id')->on('stock_items')->cascadeOnDelete();
        });

        Schema::table('stock_items', function (Blueprint $table): void {
            $table->boolean('abc_class_locked')->default(false);
            $table->string('abc_override_reason', 500)->nullable();
        });

        Schema::table('cycle_count_plans', function (Blueprint $table): void {
            $table->uuid('warehouse_zone_id')->nullable();
        });

        Schema::table('inventory_count_sessions', function (Blueprint $table): void {
            $table->boolean('is_blind')->default(true);
            $table->string('freeze_scope', 12)->default('warehouse');
            $table->timestamp('snapshot_at')->nullable();
            $table->uuid('assigned_to')->nullable()->index();
            $table->unsignedTinyInteger('approval_level_required')->nullable();
            $table->string('approval_mode', 20)->nullable();
        });

        Schema::table('inventory_count_lines', function (Blueprint $table): void {
            $table->decimal('unit_cost', 18, 6)->nullable();
            $table->decimal('system_value', 18, 2)->nullable();
            $table->decimal('variance_percentage', 10, 2)->nullable();
            $table->boolean('recount_required')->default(false);
            $table->string('recount_reason', 20)->nullable();
            $table->text('count_notes')->nullable();
        });

        Schema::create('inventory_count_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('inventory_count_session_id');
            $table->uuid('inventory_count_line_id');
            $table->unsignedTinyInteger('round')->default(0);
            $table->decimal('counted_quantity', 18, 4);
            $table->uuid('counted_by');
            $table->timestamp('counted_at');
            $table->uuid('client_entry_id')->nullable();
            $table->string('device_id', 100)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('inventory_count_session_id')->references('id')->on('inventory_count_sessions')->cascadeOnDelete();
            $table->foreign('inventory_count_line_id')->references('id')->on('inventory_count_lines')->cascadeOnDelete();
            $table->unique(['tenant_id', 'client_entry_id'], 'uq_count_entry_client_id');
        });

        Schema::create('inventory_count_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('inventory_count_session_id');
            $table->uuid('inventory_adjustment_id');
            $table->unsignedTinyInteger('level');
            $table->string('required_role', 30);
            $table->string('status', 12)->default('pending');
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('inventory_count_session_id')->references('id')->on('inventory_count_sessions')->cascadeOnDelete();
            $table->foreign('inventory_adjustment_id')->references('id')->on('inventory_adjustments')->cascadeOnDelete();
            $table->unique(['inventory_adjustment_id', 'level'], 'uq_count_approval_level');
        });

        Schema::create('inventory_count_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('inventory_count_session_id');
            $table->uuid('inventory_count_line_id')->nullable();
            $table->string('event', 40);
            $table->uuid('user_id')->nullable();
            $table->json('payload')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('inventory_count_session_id')->references('id')->on('inventory_count_sessions')->cascadeOnDelete();
            $table->index(['inventory_count_session_id', 'event'], 'idx_count_events_session');
        });

        Schema::table('inventory_adjustments', function (Blueprint $table): void {
            $table->uuid('journal_entry_id')->nullable()->index();
            $table->timestamp('gl_posted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_adjustments', function (Blueprint $table): void {
            $table->dropColumn(['journal_entry_id', 'gl_posted_at']);
        });
        Schema::dropIfExists('inventory_count_events');
        Schema::dropIfExists('inventory_count_approvals');
        Schema::dropIfExists('inventory_count_entries');
        Schema::table('inventory_count_lines', function (Blueprint $table): void {
            $table->dropColumn(['unit_cost', 'system_value', 'variance_percentage', 'recount_required', 'recount_reason', 'count_notes']);
        });
        Schema::table('inventory_count_sessions', function (Blueprint $table): void {
            $table->dropColumn(['is_blind', 'freeze_scope', 'snapshot_at', 'assigned_to', 'approval_level_required', 'approval_mode']);
        });
        Schema::table('cycle_count_plans', function (Blueprint $table): void {
            $table->dropColumn('warehouse_zone_id');
        });
        Schema::table('stock_items', function (Blueprint $table): void {
            $table->dropColumn(['abc_class_locked', 'abc_override_reason']);
        });
        Schema::dropIfExists('inventory_abc_overrides');
        Schema::dropIfExists('inventory_abc_run_items');
        Schema::dropIfExists('inventory_abc_runs');
        Schema::dropIfExists('inventory_count_policies');
    }
};
