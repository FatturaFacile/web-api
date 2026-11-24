<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounting consolidation of the legal entities (tenants) of a group, kept apart from the ledgers of the entities.
     *
     * Every table belongs to the tenant that owns the group (the parent entity): `tenant_id` is the owner, the member
     * entities are referenced by `member_tenant_id` / `entity_*_tenant_id` and are never written to. The run tables
     * (`gl_consolidation_runs`, `_run_balances`, `_adjustments`, `_adjustment_lines`) are a snapshot: a run is created
     * once and never edited, only locked, or deleted while it is not locked.
     */
    public function up(): void
    {
        Schema::create('gl_consolidation_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name', 190);
            $table->string('group_currency', 3);
            $table->string('translation_reserve_code', 40)->default('CONS-CTA');
            $table->string('consolidation_difference_code', 40)->default('CONS-DIFF');
            $table->string('equity_method_result_code', 40)->default('CONS-EQM-PL');
            $table->string('equity_method_reserve_code', 40)->default('CONS-EQM-RES');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('gl_consolidation_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('group_id')->index();
            $table->uuid('member_tenant_id')->index();
            $table->decimal('ownership_percentage', 7, 4)->default(100);
            $table->string('consolidation_method', 20)->default('full');
            $table->boolean('is_parent')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('group_id')->references('id')->on('gl_consolidation_groups')->cascadeOnDelete();
            $table->unique(['group_id', 'member_tenant_id'], 'uq_gl_consolidation_member');
        });

        Schema::create('gl_intercompany_relations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('group_id')->index();
            $table->uuid('entity_a_tenant_id');
            $table->uuid('entity_b_tenant_id');
            $table->decimal('tolerance_amount', 19, 4)->default('0.0100');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('group_id')->references('id')->on('gl_consolidation_groups')->cascadeOnDelete();
            $table->unique(['group_id', 'entity_a_tenant_id', 'entity_b_tenant_id'], 'uq_gl_intercompany_relation');
        });

        Schema::create('gl_intercompany_account_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('relation_id')->index();
            $table->string('mapping_type', 30);
            $table->uuid('account_a_id');
            $table->uuid('account_b_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('relation_id')->references('id')->on('gl_intercompany_relations')->cascadeOnDelete();
        });

        Schema::create('gl_consolidation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('group_id')->index();
            $table->uuid('fiscal_period_id')->index();
            $table->date('year_start');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('completed');
            $table->string('group_currency', 3);
            $table->string('input_fingerprint', 64);
            $table->jsonb('members_snapshot')->default('[]');
            $table->jsonb('mappings_snapshot')->default('[]');
            $table->jsonb('rates')->default('[]');
            $table->jsonb('warnings')->default('[]');
            $table->jsonb('totals')->default('{}');
            $table->uuid('created_by')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->uuid('locked_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('group_id')->references('id')->on('gl_consolidation_groups')->cascadeOnDelete();
            $table->unique(['group_id', 'fiscal_period_id', 'version'], 'uq_gl_consolidation_run_version');
        });

        Schema::create('gl_consolidation_run_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('run_id')->index();
            $table->uuid('member_tenant_id')->index();
            $table->uuid('account_id')->nullable();
            $table->string('account_code', 40);
            $table->string('account_name', 255);
            $table->string('account_type', 20);
            $table->string('normal_balance', 10);
            $table->string('consolidation_method', 20);
            $table->decimal('ownership_percentage', 7, 4);
            $table->string('local_currency', 3);
            $table->decimal('local_net', 19, 4);
            $table->string('rate_basis', 20);
            $table->decimal('rate', 16, 6);
            $table->decimal('group_net', 19, 4);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('run_id')->references('id')->on('gl_consolidation_runs')->cascadeOnDelete();
        });

        Schema::create('gl_consolidation_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('run_id')->index();
            $table->string('adjustment_type', 40);
            $table->uuid('relation_id')->nullable();
            $table->uuid('member_tenant_id')->nullable();
            $table->string('description', 500);
            $table->decimal('amount', 19, 4);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('run_id')->references('id')->on('gl_consolidation_runs')->cascadeOnDelete();
        });

        Schema::create('gl_consolidation_adjustment_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('run_id')->index();
            $table->uuid('adjustment_id')->index();
            $table->unsignedInteger('line_number');
            $table->uuid('entity_tenant_id')->nullable();
            $table->string('account_code', 40);
            $table->string('account_name', 255);
            $table->string('account_type', 20);
            $table->decimal('debit', 19, 4)->default(0);
            $table->decimal('credit', 19, 4)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('adjustment_id')->references('id')->on('gl_consolidation_adjustments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_consolidation_adjustment_lines');
        Schema::dropIfExists('gl_consolidation_adjustments');
        Schema::dropIfExists('gl_consolidation_run_balances');
        Schema::dropIfExists('gl_consolidation_runs');
        Schema::dropIfExists('gl_intercompany_account_mappings');
        Schema::dropIfExists('gl_intercompany_relations');
        Schema::dropIfExists('gl_consolidation_members');
        Schema::dropIfExists('gl_consolidation_groups');
    }
};
