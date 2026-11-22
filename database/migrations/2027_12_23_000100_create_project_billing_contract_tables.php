<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project billing (Mod 081): the billing contract of a project (model, advance, guarantee retention, cap, prepaid
 * retainer, fiscal jurisdiction), the prepaid retainer ledger, the SAL progress certificates, the guarantee retention
 * ledger and the period-end WIP valuation runs. Only new tables, dialect-agnostic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_billing_contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('company_id')->nullable();
            $table->uuid('project_id');
            $table->uuid('customer_id');
            $table->string('billing_model', 30);
            $table->string('fiscal_jurisdiction', 2);
            $table->string('currency_code', 3);
            $table->decimal('contract_total_value', 18, 4)->default(0);
            $table->decimal('advance_payment_percent', 7, 4)->default(0);
            $table->decimal('advance_payment_amount', 18, 4)->default(0);
            $table->decimal('advance_recouped_amount', 18, 4)->default(0);
            $table->decimal('retention_guarantee_percent', 7, 4)->default(0);
            $table->string('retention_release_rule', 30)->default('manual');
            $table->date('retention_release_date')->nullable();
            $table->decimal('cap_amount', 18, 4)->nullable();
            $table->decimal('markup_percent', 7, 4)->default(0);
            $table->uuid('rate_card_id')->nullable();
            $table->decimal('prepaid_hours_allowance', 12, 4)->default(0);
            $table->decimal('prepaid_amount_allowance', 18, 4)->default(0);
            $table->decimal('low_balance_alert_threshold_percent', 7, 4)->default(10);
            $table->timestamp('low_balance_alerted_at')->nullable();
            $table->string('cig', 10)->nullable();
            $table->string('cup', 15)->nullable();
            $table->string('contract_reference', 100)->nullable();
            $table->date('contract_date')->nullable();
            $table->string('billing_status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id'], 'uq_proj_billing_contract_project');
            $table->index(['tenant_id', 'billing_status'], 'idx_proj_billing_contract_status');
        });

        Schema::create('project_retainer_ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('company_id')->nullable();
            $table->uuid('project_id');
            $table->uuid('billing_contract_id');
            $table->string('entry_type', 20);
            $table->string('source_type', 30)->nullable();
            $table->string('source_id', 64)->nullable();
            $table->date('entry_date');
            $table->decimal('hours', 12, 4)->default(0);
            $table->decimal('amount', 18, 4)->default(0);
            $table->decimal('balance_hours_after', 12, 4)->default(0);
            $table->decimal('balance_amount_after', 18, 4)->default(0);
            $table->string('note', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('billing_contract_id')->references('id')->on('project_billing_contracts')->cascadeOnDelete();
            $table->unique(['billing_contract_id', 'source_type', 'source_id'], 'uq_proj_retainer_source');
            $table->index(['billing_contract_id', 'entry_date'], 'idx_proj_retainer_date');
        });

        Schema::create('project_sal_certificates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('company_id')->nullable();
            $table->uuid('project_id');
            $table->uuid('billing_contract_id');
            $table->unsignedInteger('sal_number');
            $table->date('period_start_date');
            $table->date('period_end_date');
            $table->decimal('previous_completion_percent', 7, 4)->default(0);
            $table->decimal('cumulative_completion_percent', 7, 4)->default(0);
            $table->decimal('cumulative_gross_earned_value', 18, 4)->default(0);
            $table->decimal('previous_sal_cumulative_billed', 18, 4)->default(0);
            $table->decimal('current_period_gross_sal', 18, 4)->default(0);
            $table->decimal('advance_deduction_amount', 18, 4)->default(0);
            $table->decimal('guarantee_retention_withheld', 18, 4)->default(0);
            $table->decimal('invoice_amount', 18, 4)->default(0);
            $table->decimal('net_billable_amount', 18, 4)->default(0);
            $table->boolean('is_final')->default(false);
            $table->string('status', 20)->default('draft');
            $table->timestamp('customer_approved_at')->nullable();
            $table->string('customer_approved_by', 150)->nullable();
            $table->uuid('sales_invoice_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('billing_contract_id')->references('id')->on('project_billing_contracts')->cascadeOnDelete();
            $table->index(['project_id', 'sal_number'], 'idx_proj_sal_number');
            $table->index(['billing_contract_id', 'status'], 'idx_proj_sal_status');
        });

        Schema::create('project_retention_ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('company_id')->nullable();
            $table->uuid('project_id');
            $table->uuid('billing_contract_id');
            $table->uuid('sal_certificate_id')->nullable();
            $table->string('entry_type', 12);
            $table->decimal('amount', 18, 4);
            $table->date('entry_date');
            $table->string('reason', 255)->nullable();
            $table->uuid('billing_event_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('billing_contract_id')->references('id')->on('project_billing_contracts')->cascadeOnDelete();
            $table->index(['billing_contract_id', 'entry_type'], 'idx_proj_retention_type');
        });

        Schema::create('project_wip_valuation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('company_id')->nullable();
            $table->uuid('project_id');
            $table->uuid('revenue_recognition_id')->nullable();
            $table->date('period_end_date');
            $table->string('valuation_method', 40);
            $table->string('accounting_standard', 20);
            $table->decimal('cumulative_actual_cost_to_date', 18, 4)->default(0);
            $table->decimal('estimated_total_cost_at_completion', 18, 4)->default(0);
            $table->decimal('completion_ratio', 9, 6)->default(0);
            $table->decimal('recognized_revenue_to_date', 18, 4)->default(0);
            $table->decimal('billed_to_date', 18, 4)->default(0);
            $table->decimal('wip_asset_or_deferred_revenue_diff', 18, 4)->default(0);
            $table->decimal('expected_loss_provision', 18, 4)->default(0);
            $table->string('gl_journal_entry_id', 64)->nullable();
            $table->string('status', 12)->default('draft');
            $table->uuid('run_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'period_end_date'], 'uq_proj_wip_run_period');
            $table->index(['tenant_id', 'period_end_date'], 'idx_proj_wip_run_tenant_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_wip_valuation_runs');
        Schema::dropIfExists('project_retention_ledger_entries');
        Schema::dropIfExists('project_sal_certificates');
        Schema::dropIfExists('project_retainer_ledger_entries');
        Schema::dropIfExists('project_billing_contracts');
    }
};
