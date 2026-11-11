<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extensions of the consolidation (additive only; every default keeps the behaviour of the first version):
     * group settings for the elimination policy, goodwill and the synthetic accounts; indirect holdings and fiscal year
     * offset on the members; the mapping of the accounts of each member to the group chart; the acquisition records
     * with fair-value adjustments and goodwill impairments; intragroup transactions with unrealised profit; intragroup
     * dividends. Everything is owned by the tenant of the group, like the tables of the first migration.
     */
    public function up(): void
    {
        Schema::table('gl_consolidation_groups', function (Blueprint $table): void {
            $table->string('elimination_difference_policy', 40)->default('keep_difference');
            $table->string('goodwill_method', 30)->default('none');
            $table->unsignedSmallInteger('goodwill_amortisation_years')->nullable();
            $table->boolean('require_account_mapping')->default(false);
            $table->jsonb('synthetic_accounts')->default('{}');
        });

        Schema::table('gl_consolidation_members', function (Blueprint $table): void {
            $table->uuid('holder_tenant_id')->nullable();
            $table->smallInteger('fiscal_year_offset_months')->default(0);
        });

        Schema::table('gl_consolidation_run_balances', function (Blueprint $table): void {
            $table->decimal('consolidated_share', 7, 4)->default(100);
        });

        Schema::table('gl_consolidation_runs', function (Blueprint $table): void {
            $table->jsonb('inputs_snapshot')->default('{}');
        });

        Schema::create('gl_consolidation_account_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('group_id')->index();
            $table->uuid('member_tenant_id');
            $table->uuid('account_id');
            $table->string('group_account_code', 40);
            $table->string('group_account_name', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('group_id')->references('id')->on('gl_consolidation_groups')->cascadeOnDelete();
            $table->unique(['group_id', 'member_tenant_id', 'account_id'], 'uq_gl_consolidation_account_mapping');
        });

        Schema::create('gl_consolidation_acquisitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('group_id')->index();
            $table->uuid('member_tenant_id');
            $table->date('acquisition_date');
            $table->decimal('cost', 19, 4)->nullable();
            $table->decimal('net_assets_at_acquisition', 19, 4);
            $table->decimal('acquired_percentage', 7, 4)->nullable();
            $table->boolean('recognise_deferred_tax')->default(false);
            $table->decimal('deferred_tax_rate', 7, 4)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('group_id')->references('id')->on('gl_consolidation_groups')->cascadeOnDelete();
            $table->unique(['group_id', 'member_tenant_id'], 'uq_gl_consolidation_acquisition');
        });

        Schema::create('gl_consolidation_fair_value_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('acquisition_id')->index();
            $table->string('account_code', 40);
            $table->string('account_name', 255)->nullable();
            $table->string('account_type', 20);
            $table->decimal('amount', 19, 4);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('acquisition_id')->references('id')->on('gl_consolidation_acquisitions')->cascadeOnDelete();
        });

        Schema::create('gl_consolidation_goodwill_impairments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('acquisition_id')->index();
            $table->unsignedSmallInteger('fiscal_year');
            $table->decimal('amount', 19, 4);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('acquisition_id')->references('id')->on('gl_consolidation_acquisitions')->cascadeOnDelete();
            $table->unique(['acquisition_id', 'fiscal_year'], 'uq_gl_consolidation_goodwill_impairment');
        });

        Schema::create('gl_intragroup_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('group_id')->index();
            $table->uuid('relation_id')->nullable();
            $table->uuid('seller_tenant_id');
            $table->uuid('buyer_tenant_id');
            $table->string('asset_type', 20);
            $table->string('description', 255)->nullable();
            $table->date('transaction_date');
            $table->decimal('sale_price', 19, 4)->nullable();
            $table->decimal('seller_cost', 19, 4)->nullable();
            $table->decimal('margin_amount', 19, 4)->nullable();
            $table->decimal('remaining_percentage', 7, 4)->default(100);
            $table->decimal('opening_remaining_percentage', 7, 4)->nullable();
            $table->unsignedSmallInteger('useful_life_years')->nullable();
            $table->uuid('asset_account_id');
            $table->uuid('profit_account_id');
            $table->uuid('depreciation_account_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('group_id')->references('id')->on('gl_consolidation_groups')->cascadeOnDelete();
        });

        Schema::create('gl_intragroup_dividends', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('group_id')->index();
            $table->uuid('payer_tenant_id');
            $table->uuid('receiver_tenant_id');
            $table->date('dividend_date');
            $table->decimal('amount', 19, 4);
            $table->uuid('income_account_id');
            $table->uuid('equity_account_id');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('group_id')->references('id')->on('gl_consolidation_groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_intragroup_dividends');
        Schema::dropIfExists('gl_intragroup_transactions');
        Schema::dropIfExists('gl_consolidation_goodwill_impairments');
        Schema::dropIfExists('gl_consolidation_fair_value_adjustments');
        Schema::dropIfExists('gl_consolidation_acquisitions');
        Schema::dropIfExists('gl_consolidation_account_mappings');

        Schema::table('gl_consolidation_runs', function (Blueprint $table): void {
            $table->dropColumn('inputs_snapshot');
        });
        Schema::table('gl_consolidation_run_balances', function (Blueprint $table): void {
            $table->dropColumn('consolidated_share');
        });
        Schema::table('gl_consolidation_members', function (Blueprint $table): void {
            $table->dropColumn(['holder_tenant_id', 'fiscal_year_offset_months']);
        });
        Schema::table('gl_consolidation_groups', function (Blueprint $table): void {
            $table->dropColumn(['elimination_difference_policy', 'goodwill_method', 'goodwill_amortisation_years', 'require_account_mapping', 'synthetic_accounts']);
        });
    }
};
