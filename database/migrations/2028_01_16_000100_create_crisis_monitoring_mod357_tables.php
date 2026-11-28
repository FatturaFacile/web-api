<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corporate crisis monitoring (Mod 357): the per-company crisis profile, the rolling DSCR projections, the Italian CCII and the
 * Swiss CO 725 assessments, the subordinated loans registry (Rangrücktritt) and the reference data (CNDCEC ATECO thresholds,
 * Art. 25-novies public creditor thresholds, CNC practical test tiers). Dialect-agnostic: runs unchanged on SQLite and PostgreSQL;
 * tenant isolation is the application-level `tenant_id` scope shared by every table of the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_cndcec_ateco_thresholds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('ateco_section', 1); // A..S
            $table->string('sector_label_key', 80);
            $table->string('cndcec_group', 40);
            // Alert thresholds in percent: higher is worse (max) for financial charges and tax & social debt, lower is worse (min) for the others.
            $table->decimal('financial_charges_revenue_max', 8, 2);
            $table->decimal('equity_total_debt_min', 8, 2);
            $table->decimal('liquidity_ratio_min', 8, 2);
            $table->decimal('cash_flow_assets_min', 8, 2);
            $table->decimal('tax_social_debt_assets_max', 8, 2);
            $table->string('source_note', 200)->nullable();
            $table->date('valid_from')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->index(['tenant_id', 'ateco_section', 'is_active'], 'fin_cat_section_idx');
        });

        Schema::create('fin_public_creditor_thresholds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('creditor_code', 20); // payroll, supplier, inps, inail, ade_vat, ader
            $table->string('legal_form', 24)->nullable(); // null = every legal form
            $table->string('label_key', 80);
            $table->string('description_key', 80);
            $table->unsignedSmallInteger('overdue_days')->nullable();
            $table->decimal('absolute_threshold', 15, 2)->nullable();
            $table->decimal('absolute_threshold_no_employees', 15, 2)->nullable();
            $table->decimal('absolute_always_threshold', 15, 2)->nullable(); // breach on its own, whatever the percentage says
            $table->decimal('percent_threshold', 7, 2)->nullable();
            $table->string('percent_basis', 32)->nullable(); // monthly_payroll, non_overdue_suppliers, prior_year_contributions, prior_year_turnover
            $table->string('combine', 8)->default('and'); // and, or
            $table->decimal('warning_ratio', 4, 2)->default(0.70);
            $table->string('legal_reference', 160)->nullable();
            $table->date('valid_from')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->index(['tenant_id', 'creditor_code', 'is_active'], 'fin_pct_creditor_idx');
        });

        Schema::create('fin_cnc_practical_test_tiers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('label_key', 80);
            $table->decimal('ratio_up_to', 8, 2)->nullable(); // inclusive upper bound; null = open-ended last tier
            $table->string('status', 8); // green, yellow, red
            $table->unsignedSmallInteger('sort_order');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->index(['tenant_id', 'sort_order'], 'fin_cnc_tier_idx');
        });

        Schema::create('fin_crisis_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('jurisdiction', 2)->default('IT');
            $table->string('ateco_code', 12)->nullable();
            $table->string('ateco_section', 1)->nullable();
            $table->string('legal_form', 24)->default('corporation'); // corporation, partnership, sole_proprietorship
            $table->unsignedInteger('employees_count')->default(0);
            $table->decimal('share_capital', 15, 2)->nullable();
            $table->decimal('statutory_reserves', 15, 2)->nullable();
            $table->decimal('legal_minimum_capital', 15, 2)->nullable();
            $table->decimal('prior_year_revenue', 15, 2)->nullable();
            $table->decimal('prior_year_inps_contributions', 15, 2)->nullable();
            $table->decimal('available_credit_lines', 15, 2)->nullable();
            $table->decimal('variable_debt_amount', 15, 2)->nullable();
            $table->decimal('total_assets_override', 15, 2)->nullable();
            $table->decimal('current_assets_override', 15, 2)->nullable();
            $table->decimal('current_liabilities_override', 15, 2)->nullable();
            $table->decimal('total_debt_override', 15, 2)->nullable();
            $table->decimal('net_equity_override', 15, 2)->nullable();
            $table->decimal('revenue_override', 15, 2)->nullable();
            $table->decimal('financial_expenses_override', 15, 2)->nullable();
            $table->decimal('cash_flow_override', 15, 2)->nullable();
            $table->decimal('tax_social_debt_override', 15, 2)->nullable();
            $table->decimal('payroll_overdue_30d_amount', 15, 2)->nullable();
            $table->decimal('supplier_overdue_90d_amount', 15, 2)->nullable();
            $table->decimal('inps_overdue_90d_amount', 15, 2)->nullable();
            $table->decimal('inail_overdue_90d_amount', 15, 2)->nullable();
            $table->decimal('vat_lipe_unpaid_amount', 15, 2)->nullable();
            $table->decimal('ader_entrusted_amount', 15, 2)->nullable();
            $table->decimal('assets_going_concern', 15, 2)->nullable();
            $table->decimal('assets_liquidation', 15, 2)->nullable();
            $table->decimal('total_liabilities_override', 15, 2)->nullable();
            $table->boolean('going_concern_probable')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
        });

        Schema::create('fin_dscr_monthly_projections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->date('month');
            $table->string('currency', 3);
            $table->decimal('operating_inflows', 15, 2)->default(0);
            $table->decimal('operating_outflows', 15, 2)->default(0);
            $table->decimal('debt_service', 15, 2)->default(0);
            $table->decimal('tax_social', 15, 2)->default(0);
            $table->decimal('capex', 15, 2)->default(0);
            $table->decimal('free_cash_flow', 15, 2)->default(0);
            $table->decimal('closing_cash', 15, 2)->default(0);
            $table->decimal('cumulative_cash_with_credit', 15, 2)->default(0);
            $table->decimal('dscr_treasury', 10, 2)->nullable();
            $table->decimal('dscr_operating', 10, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->unique(['tenant_id', 'snapshot_date', 'month'], 'fin_dscr_proj_unique');
        });

        Schema::create('fin_ccii_crisis_assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->date('assessment_date');
            $table->string('trigger', 12)->default('scheduled'); // scheduled, manual
            $table->string('overall_status', 8);
            $table->decimal('dscr_6m_treasury', 10, 2)->nullable();
            $table->decimal('dscr_12m_treasury', 10, 2)->nullable();
            $table->decimal('dscr_6m_operating', 10, 2)->nullable();
            $table->decimal('dscr_12m_operating', 10, 2)->nullable();
            $table->json('indicators');
            $table->json('public_creditors');
            $table->json('cnc_test');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->unique(['tenant_id', 'assessment_date'], 'fin_ccii_assess_unique');
        });

        Schema::create('fin_swiss_co725_assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->date('assessment_date');
            $table->string('trigger', 12)->default('scheduled');
            $table->string('overall_status', 8);
            $table->string('art725_status', 8);
            $table->string('art725a_status', 8);
            $table->string('art725b_status', 8);
            $table->decimal('min_cumulative_cash', 15, 2)->nullable();
            $table->date('first_negative_month')->nullable();
            $table->decimal('net_assets', 15, 2)->nullable();
            $table->decimal('capital_coverage_ratio', 10, 4)->nullable();
            $table->decimal('net_going_concern', 15, 2)->nullable();
            $table->decimal('net_liquidation', 15, 2)->nullable();
            $table->decimal('required_subordination', 15, 2)->default(0);
            $table->boolean('court_notification_required')->default(false);
            $table->json('details');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->unique(['tenant_id', 'assessment_date'], 'fin_co725_assess_unique');
        });

        Schema::create('fin_subordinated_loans_rangruecktritt', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('creditor_name', 150);
            $table->string('creditor_type', 16)->default('shareholder'); // shareholder, third_party
            $table->decimal('principal', 15, 2);
            $table->decimal('interest_rate', 6, 2)->default(0); // percent per year, simple interest
            $table->string('interest_treatment', 16)->default('deferred'); // deferred (accrues, is subordinated too), paid_current
            $table->date('agreement_date');
            $table->date('effective_from');
            $table->date('deferral_end_date')->nullable();
            $table->string('scope', 24)->default('principal_and_interest'); // principal, principal_and_interest
            $table->string('status', 12)->default('draft'); // draft, active, released
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->index(['tenant_id', 'status'], 'fin_sub_loans_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_subordinated_loans_rangruecktritt');
        Schema::dropIfExists('fin_swiss_co725_assessments');
        Schema::dropIfExists('fin_ccii_crisis_assessments');
        Schema::dropIfExists('fin_dscr_monthly_projections');
        Schema::dropIfExists('fin_crisis_profiles');
        Schema::dropIfExists('fin_cnc_practical_test_tiers');
        Schema::dropIfExists('fin_public_creditor_thresholds');
        Schema::dropIfExists('fin_cndcec_ateco_thresholds');
    }
};
