<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ESG and carbon accounting (GHG Protocol, CSRD/ESRS): emission factor library, activity data with the factor frozen
     * on every record, reporting periods, reduction targets, ESG KPI definitions with periodic values and double
     * materiality assessments. New tables only; business rules are enforced by the application, not by the engine.
     */
    public function up(): void
    {
        if (! Schema::hasTable('esg_emission_factors')) {
            Schema::create('esg_emission_factors', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 60);
                $table->unsignedInteger('version')->default(1);
                $table->string('name', 190);
                $table->string('activity_type', 40);
                $table->string('unit', 10);
                $table->unsignedTinyInteger('scope');
                $table->string('category', 60);
                $table->decimal('kg_co2e_per_unit', 18, 8);
                $table->string('region', 10)->default('EU');
                $table->string('source', 190);
                $table->unsignedSmallInteger('source_year');
                $table->boolean('is_example')->default(false);
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code', 'version']);
                $table->index(['tenant_id', 'activity_type', 'region']);
                $table->index(['tenant_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('esg_reporting_periods')) {
            Schema::create('esg_reporting_periods', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 120);
                $table->date('starts_on');
                $table->date('ends_on');
                $table->string('status', 20)->default('open');
                $table->decimal('revenue', 18, 2)->nullable();
                $table->string('currency', 3)->default('EUR');
                $table->decimal('employees_fte', 12, 2)->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->uuid('closed_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'starts_on']);
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('esg_activity_records')) {
            Schema::create('esg_activity_records', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('emission_factor_id');
                $table->date('activity_date');
                $table->decimal('quantity', 20, 4);
                $table->string('unit', 10);
                $table->string('activity_type', 40);
                $table->unsignedTinyInteger('scope');
                $table->string('category', 60);
                $table->string('factor_code', 60);
                $table->unsignedInteger('factor_version');
                $table->decimal('factor_kg_co2e_per_unit', 18, 8);
                $table->string('factor_source', 190);
                $table->decimal('co2e_kg', 22, 4);
                $table->string('data_quality', 20)->default('measured');
                $table->string('site', 120)->nullable();
                $table->string('reference', 120)->nullable();
                $table->string('source_type', 60)->nullable();
                $table->uuid('source_id')->nullable();
                $table->text('notes')->nullable();
                $table->uuid('recorded_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'activity_date']);
                $table->index(['tenant_id', 'scope', 'category']);
                $table->index(['tenant_id', 'emission_factor_id']);
                $table->index(['tenant_id', 'source_type', 'source_id']);
            });
        }

        if (! Schema::hasTable('esg_reduction_targets')) {
            Schema::create('esg_reduction_targets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 190);
                $table->string('scope', 10);
                $table->unsignedSmallInteger('baseline_year');
                $table->decimal('baseline_co2e_kg', 22, 4)->nullable();
                $table->unsignedSmallInteger('target_year');
                $table->decimal('reduction_percent', 6, 2);
                $table->string('status', 20)->default('draft');
                $table->text('notes')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('evaluated_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'target_year']);
            });
        }

        if (! Schema::hasTable('esg_kpi_definitions')) {
            Schema::create('esg_kpi_definitions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 60);
                $table->string('name', 190);
                $table->string('pillar', 20);
                $table->string('unit', 20);
                $table->string('direction', 20)->default('lower_is_better');
                $table->string('esrs_reference', 30)->nullable();
                $table->decimal('target_value', 18, 4)->nullable();
                $table->boolean('is_example')->default(false);
                $table->boolean('is_active')->default(true);
                $table->text('description')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
                $table->index(['tenant_id', 'pillar']);
            });
        }

        if (! Schema::hasTable('esg_kpi_values')) {
            Schema::create('esg_kpi_values', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('kpi_definition_id');
                $table->uuid('reporting_period_id');
                $table->decimal('value', 20, 4);
                $table->string('data_quality', 20)->default('measured');
                $table->text('notes')->nullable();
                $table->uuid('recorded_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'kpi_definition_id', 'reporting_period_id'], 'esg_kpi_values_unique');
                $table->index(['tenant_id', 'reporting_period_id']);
            });
        }

        if (! Schema::hasTable('esg_materiality_assessments')) {
            Schema::create('esg_materiality_assessments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 190);
                $table->uuid('reporting_period_id')->nullable();
                $table->decimal('threshold', 4, 2)->default(3);
                $table->string('status', 20)->default('draft');
                $table->text('notes')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->uuid('approved_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'reporting_period_id']);
            });
        }

        if (! Schema::hasTable('esg_materiality_topics')) {
            Schema::create('esg_materiality_topics', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('assessment_id');
                $table->string('esrs_code', 10);
                $table->string('name', 190);
                $table->decimal('impact_score', 4, 2);
                $table->decimal('financial_score', 4, 2);
                $table->boolean('is_material')->default(false);
                $table->text('rationale')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'assessment_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['esg_materiality_topics', 'esg_materiality_assessments', 'esg_kpi_values', 'esg_kpi_definitions', 'esg_reduction_targets', 'esg_activity_records', 'esg_reporting_periods', 'esg_emission_factors'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
