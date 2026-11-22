<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project profitability and earned value management (Mod 082): the analytical cost ledger (committed and actual cost by
 * source and WBS node), the financial snapshots at project and WBS level, the manual bottom-up ETC overrides and the
 * state of the threshold alerts. Only new tables, dialect-agnostic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_cost_ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('company_id')->nullable();
            $table->uuid('project_id');
            $table->uuid('wbs_node_id')->nullable();
            $table->uuid('cost_center_id')->nullable();
            $table->string('source_type', 40);
            $table->string('source_document_id', 64);
            $table->string('source_document_reference', 100)->nullable();
            $table->decimal('committed_amount', 18, 4)->default(0);
            $table->decimal('actual_amount', 18, 4)->default(0);
            $table->string('currency_code', 3);
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('base_currency_committed_amount', 18, 4)->default(0);
            $table->decimal('base_currency_actual_amount', 18, 4)->default(0);
            $table->date('posting_date');
            $table->boolean('is_reconciled')->default(false);
            $table->string('reconciliation_ref', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'source_type', 'source_document_id'], 'uq_proj_cost_ledger_source');
            $table->index(['tenant_id', 'project_id', 'wbs_node_id'], 'idx_proj_cost_ledger_wbs');
            $table->index(['project_id', 'posting_date'], 'idx_proj_cost_ledger_date');
        });

        Schema::create('project_financial_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('company_id')->nullable();
            $table->uuid('project_id');
            $table->uuid('wbs_node_id')->nullable();
            $table->date('snapshot_date');
            $table->string('currency_code', 3);
            $table->decimal('budget_at_completion_bac', 18, 4)->default(0);
            $table->decimal('planned_value_pv', 18, 4)->default(0);
            $table->decimal('earned_value_ev', 18, 4)->default(0);
            $table->decimal('actual_cost_ac', 18, 4)->default(0);
            $table->decimal('committed_cost', 18, 4)->default(0);
            $table->decimal('cost_variance_cv', 18, 4)->default(0);
            $table->decimal('schedule_variance_sv', 18, 4)->default(0);
            $table->decimal('cost_performance_index_cpi', 10, 4)->nullable();
            $table->decimal('schedule_performance_index_spi', 10, 4)->nullable();
            $table->decimal('estimate_at_completion_eac', 18, 4)->default(0);
            $table->decimal('estimate_to_complete_etc', 18, 4)->default(0);
            $table->decimal('variance_at_completion_vac', 18, 4)->default(0);
            $table->decimal('budget_absorption_percent', 10, 2)->nullable();
            $table->decimal('recognized_revenue', 18, 4)->default(0);
            $table->decimal('gross_margin_amount', 18, 4)->default(0);
            $table->decimal('gross_margin_percent', 10, 2)->nullable();
            $table->decimal('manual_etc_override', 18, 4)->nullable();
            $table->string('snapshot_trigger', 40)->default('manual');
            $table->string('calculation_version', 20)->default('1.0');
            $table->json('reconciliation')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->index(['tenant_id', 'project_id', 'snapshot_date'], 'idx_proj_fin_snapshot_date');
            $table->index(['project_id', 'wbs_node_id', 'snapshot_date'], 'idx_proj_fin_snapshot_wbs');
        });

        Schema::create('project_etc_overrides', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('company_id')->nullable();
            $table->uuid('project_id');
            $table->uuid('wbs_node_id')->nullable();
            $table->decimal('estimate_to_complete', 18, 4);
            $table->string('reason', 500)->nullable();
            $table->uuid('set_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->index(['project_id', 'wbs_node_id'], 'idx_proj_etc_override_node');
        });

        Schema::create('project_evm_alert_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('company_id')->nullable();
            $table->uuid('project_id');
            $table->uuid('wbs_node_id')->nullable();
            $table->string('metric', 20);
            $table->string('node_key', 40);
            $table->string('severity', 20);
            $table->decimal('metric_value', 18, 4);
            $table->decimal('threshold', 18, 4);
            $table->boolean('is_open')->default(true);
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'node_key', 'metric'], 'uq_proj_evm_alert_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_evm_alert_states');
        Schema::dropIfExists('project_etc_overrides');
        Schema::dropIfExists('project_financial_snapshots');
        Schema::dropIfExists('project_cost_ledger_entries');
    }
};
