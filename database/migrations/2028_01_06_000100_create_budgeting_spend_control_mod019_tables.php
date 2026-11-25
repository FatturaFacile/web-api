<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mod 019 — budgeting and variance analysis. Extends the planning tables (granularity, scenarios, revisions,
     * quantity and unit price per line) and adds the spend control: tenant settings, spend rules, commitments of
     * requisitions and purchase orders, the log of every control, override (deroga) requests, the actual volumes used
     * by the volume and price decomposition, the Excel imports and a generic audit trail. Accounts, cost centres and
     * projects are referenced by id without foreign keys, as in the planning tables.
     */
    public function up(): void
    {
        Schema::table('budget_versions', function (Blueprint $table): void {
            $table->string('granularity', 10)->default('monthly')->after('currency');
            $table->string('scenario_kind', 30)->nullable()->after('version_type');
            $table->unsignedSmallInteger('revision_number')->default(0)->after('scenario_kind');
            $table->string('revision_reason', 1000)->nullable()->after('revision_number');
            $table->unsignedTinyInteger('rolling_horizon_months')->nullable()->after('reforecast_from_period');
        });

        Schema::table('budget_lines', function (Blueprint $table): void {
            $table->decimal('quantity', 15, 3)->nullable()->after('amount');
            $table->decimal('unit_price', 15, 4)->nullable()->after('quantity');
        });

        Schema::create('budget_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->decimal('amber_threshold_percent', 6, 2)->default(5);
            $table->decimal('red_threshold_percent', 6, 2)->default(10);
            $table->unsignedSmallInteger('override_validity_days')->default(30);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('tenant_id');
        });

        Schema::create('budget_spend_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 160);
            $table->uuid('account_id')->nullable()->index();
            $table->uuid('cost_center_id')->nullable()->index();
            $table->uuid('project_id')->nullable()->index();
            $table->string('mode', 20)->default('allow');
            $table->decimal('threshold_percent', 7, 2)->default(100);
            $table->json('document_types');
            $table->json('stages');
            $table->boolean('enforce_without_budget')->default(false);
            $table->smallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('budget_commitments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('source_type', 30);
            $table->uuid('source_id');
            $table->string('source_number', 60)->nullable();
            $table->uuid('parent_source_id')->nullable()->index();
            $table->uuid('account_id')->nullable()->index();
            $table->uuid('cost_center_id')->nullable()->index();
            $table->uuid('project_id')->nullable()->index();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('period')->nullable();
            $table->date('commitment_date');
            $table->string('currency', 3);
            $table->decimal('amount', 15, 2);
            $table->decimal('consumed_amount', 15, 2)->default(0);
            $table->decimal('released_amount', 15, 2)->default(0);
            $table->string('status', 20)->default('open');
            $table->string('release_reason', 40)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'source_type', 'source_id'], 'budget_commitments_source');
            $table->index(['tenant_id', 'status', 'fiscal_year'], 'budget_commitments_open');
        });

        Schema::create('budget_overrides', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('scope', 20)->default('document');
            $table->string('source_type', 30)->nullable();
            $table->uuid('source_id')->nullable()->index();
            $table->string('source_number', 60)->nullable();
            $table->uuid('account_id')->nullable()->index();
            $table->uuid('cost_center_id')->nullable()->index();
            $table->uuid('project_id')->nullable()->index();
            $table->unsignedSmallInteger('fiscal_year');
            $table->decimal('requested_amount', 15, 2);
            $table->decimal('shortfall_amount', 15, 2)->default(0);
            $table->decimal('approved_amount', 15, 2)->nullable();
            $table->decimal('consumed_amount', 15, 2)->default(0);
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->uuid('requested_by')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_comment', 1000)->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('budget_spend_checks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('source_type', 30);
            $table->uuid('source_id')->nullable()->index();
            $table->string('source_number', 60)->nullable();
            $table->string('stage', 20);
            $table->string('outcome', 20);
            $table->uuid('rule_id')->nullable();
            $table->uuid('override_id')->nullable();
            $table->uuid('account_id')->nullable();
            $table->uuid('cost_center_id')->nullable()->index();
            $table->uuid('project_id')->nullable();
            $table->unsignedSmallInteger('fiscal_year');
            $table->decimal('budget_amount', 15, 2)->default(0);
            $table->decimal('actual_amount', 15, 2)->default(0);
            $table->decimal('committed_amount', 15, 2)->default(0);
            $table->decimal('requested_amount', 15, 2)->default(0);
            $table->decimal('available_amount', 15, 2)->default(0);
            $table->decimal('projected_percent', 9, 2)->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'outcome', 'created_at']);
        });

        Schema::create('budget_actual_volumes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('account_id')->index();
            $table->uuid('cost_center_id')->nullable()->index();
            $table->uuid('project_id')->nullable()->index();
            $table->string('month', 7);
            $table->decimal('quantity', 15, 3);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'month']);
        });

        Schema::create('budget_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('version_id')->constrained('budget_versions')->cascadeOnDelete();
            $table->string('file_name', 255);
            $table->string('format', 10);
            $table->string('mode', 10);
            $table->string('status', 20);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->json('errors')->nullable();
            $table->uuid('user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'version_id']);
            $table->index(['version_id', 'created_at']);
        });

        Schema::create('budget_audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('subject_type', 30);
            $table->uuid('subject_id')->index();
            $table->string('action', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('comment', 1000)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'budget_audit_subject');
        });
    }

    public function down(): void
    {
        foreach (['budget_audit_events', 'budget_imports', 'budget_actual_volumes', 'budget_spend_checks', 'budget_overrides', 'budget_commitments', 'budget_spend_rules', 'budget_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('budget_lines', function (Blueprint $table): void {
            $table->dropColumn(['quantity', 'unit_price']);
        });

        Schema::table('budget_versions', function (Blueprint $table): void {
            $table->dropColumn(['granularity', 'scenario_kind', 'revision_number', 'revision_reason', 'rolling_horizon_months']);
        });
    }
};
