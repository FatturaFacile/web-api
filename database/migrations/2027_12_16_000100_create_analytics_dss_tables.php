<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Executive DSS analytical data mart (Mod 093, Issue #214): conformed dimensions, the financial, commercial and
 * operational fact tables and the pre-aggregated daily snapshot. Portable SQL only: the mart is maintained by queued
 * projectors, so it behaves the same on SQLite (tests) and PostgreSQL (production).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dim_date', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->unsignedInteger('date_key');
            $table->date('date');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('quarter');
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('week');
            $table->unsignedTinyInteger('day_of_week');
            $table->boolean('is_weekend')->default(false);
            $table->boolean('is_ch_holiday')->default(false);
            $table->boolean('is_it_holiday')->default(false);
            $table->unsignedTinyInteger('working_days')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'date_key'], 'dim_date_tenant_key_unique');
        });

        Schema::create('dim_legal_entity', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('legal_entity_id');
            $table->string('name');
            $table->string('base_currency', 3);
            $table->string('country', 2)->nullable();
            $table->string('region', 64)->nullable();
            $table->uuid('parent_legal_entity_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'legal_entity_id'], 'dim_le_tenant_entity_unique');
        });

        Schema::create('dim_counterparty', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('contact_id');
            $table->string('name');
            $table->string('kind', 16);
            $table->string('country', 2)->nullable();
            $table->string('industry_code', 16)->nullable();
            $table->string('industry_code_system', 8)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->string('cohort', 7)->nullable();
            $table->string('credit_tier', 1)->default('B');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'contact_id'], 'dim_cp_tenant_contact_unique');
        });

        Schema::create('dim_product_service', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('product_id');
            $table->string('sku', 64)->nullable();
            $table->string('name');
            $table->string('category', 64)->nullable();
            $table->char('abc_class', 1)->nullable();
            $table->char('xyz_class', 1)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'product_id'], 'dim_ps_tenant_product_unique');
        });

        Schema::create('dim_cost_center', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('cost_center_id');
            $table->string('code', 32)->nullable();
            $table->string('name');
            $table->string('kind', 24)->default('department');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'cost_center_id'], 'dim_cc_tenant_center_unique');
        });

        Schema::create('fact_financial_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('legal_entity_id')->nullable();
            $table->uuid('journal_line_id');
            $table->uuid('journal_entry_id');
            $table->unsignedInteger('date_key');
            $table->date('posting_date');
            $table->uuid('account_id');
            $table->string('account_code', 32)->nullable();
            $table->string('category', 16);
            $table->uuid('cost_center_id')->nullable();
            $table->uuid('counterparty_id')->nullable();
            $table->uuid('product_id')->nullable();
            $table->uuid('project_id')->nullable();
            $table->string('channel', 32)->nullable();
            $table->string('transaction_currency', 3);
            $table->decimal('amount_transaction', 18, 4)->default(0);
            $table->string('functional_currency', 3);
            $table->decimal('amount_functional', 18, 4)->default(0);
            $table->decimal('amount_chf', 18, 4)->nullable();
            $table->decimal('amount_eur', 18, 4)->nullable();
            $table->decimal('fx_rate_chf', 18, 8)->nullable();
            $table->decimal('fx_rate_eur', 18, 8)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'journal_line_id'], 'fft_tenant_line_unique');
            $table->index(['tenant_id', 'legal_entity_id', 'date_key', 'category'], 'fft_scope_date_category_index');
            $table->index(['tenant_id', 'counterparty_id', 'category'], 'fft_counterparty_index');
            $table->index(['tenant_id', 'journal_entry_id'], 'fft_entry_index');
        });

        Schema::create('fact_commercial_velocity', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('legal_entity_id')->nullable();
            $table->uuid('invoice_id');
            $table->uuid('counterparty_id')->nullable();
            $table->uuid('quote_id')->nullable();
            $table->string('channel', 24)->default('direct');
            $table->unsignedInteger('issue_date_key');
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->date('paid_date')->nullable();
            $table->string('currency', 3);
            $table->decimal('amount_net', 18, 4)->default(0);
            $table->decimal('amount_gross', 18, 4)->default(0);
            $table->decimal('amount_paid', 18, 4)->default(0);
            $table->decimal('amount_open', 18, 4)->default(0);
            $table->decimal('amount_net_chf', 18, 4)->nullable();
            $table->decimal('amount_net_eur', 18, 4)->nullable();
            $table->decimal('amount_open_chf', 18, 4)->nullable();
            $table->decimal('amount_open_eur', 18, 4)->nullable();
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->integer('days_to_pay')->nullable();
            $table->integer('quote_to_cash_days')->nullable();
            $table->string('payment_status', 24);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'invoice_id'], 'fcv_tenant_invoice_unique');
            $table->index(['tenant_id', 'legal_entity_id', 'issue_date_key'], 'fcv_scope_date_index');
        });

        Schema::create('fact_operational_efficiency', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('legal_entity_id')->nullable();
            $table->string('source_type', 24);
            $table->uuid('source_id');
            $table->unsignedInteger('date_key');
            $table->date('work_date');
            $table->uuid('project_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->uuid('product_id')->nullable();
            $table->decimal('billable_hours', 12, 4)->default(0);
            $table->decimal('non_billable_hours', 12, 4)->default(0);
            $table->decimal('labour_cost', 18, 4)->default(0);
            $table->decimal('labour_charge', 18, 4)->default(0);
            $table->decimal('quantity_out', 18, 4)->default(0);
            $table->decimal('value_out', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 6)->nullable();
            $table->decimal('standard_unit_cost', 18, 6)->nullable();
            $table->decimal('unit_cost_variance', 18, 6)->nullable();
            $table->integer('fulfillment_lead_days')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'source_type', 'source_id'], 'foe_tenant_source_unique');
            $table->index(['tenant_id', 'legal_entity_id', 'date_key'], 'foe_scope_date_index');
        });

        Schema::create('agg_executive_daily_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('legal_entity_id')->nullable();
            $table->unsignedInteger('date_key');
            $table->date('snapshot_date');
            $table->decimal('revenue_chf', 18, 4)->default(0);
            $table->decimal('revenue_eur', 18, 4)->default(0);
            $table->decimal('cogs_chf', 18, 4)->default(0);
            $table->decimal('cogs_eur', 18, 4)->default(0);
            $table->decimal('opex_chf', 18, 4)->default(0);
            $table->decimal('opex_eur', 18, 4)->default(0);
            $table->decimal('depreciation_chf', 18, 4)->default(0);
            $table->decimal('depreciation_eur', 18, 4)->default(0);
            $table->decimal('cash_movement_chf', 18, 4)->default(0);
            $table->decimal('cash_movement_eur', 18, 4)->default(0);
            $table->decimal('receivable_movement_chf', 18, 4)->default(0);
            $table->decimal('receivable_movement_eur', 18, 4)->default(0);
            $table->decimal('payable_movement_chf', 18, 4)->default(0);
            $table->decimal('payable_movement_eur', 18, 4)->default(0);
            $table->decimal('inventory_movement_chf', 18, 4)->default(0);
            $table->decimal('inventory_movement_eur', 18, 4)->default(0);
            $table->decimal('functional_checksum', 18, 4)->default(0);
            $table->unsignedInteger('transaction_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'legal_entity_id', 'date_key'], 'aeds_scope_date_unique');
            $table->index(['tenant_id', 'date_key'], 'aeds_tenant_date_index');
        });
    }

    public function down(): void
    {
        foreach (['agg_executive_daily_snapshots', 'fact_operational_efficiency', 'fact_commercial_velocity', 'fact_financial_transactions', 'dim_cost_center', 'dim_product_service', 'dim_counterparty', 'dim_legal_entity', 'dim_date'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
