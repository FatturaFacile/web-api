<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Self-service reporting of the Analytics module (additive): reports designed on the semantic layer, their scheduled
     * exports, the files those exports produced (the audit trail of every export lives in the audit ledger as well) and
     * the daily snapshots of the heaviest KPIs.
     */
    public function up(): void
    {
        if (! Schema::hasTable('analytics_designed_reports')) {
            Schema::create('analytics_designed_reports', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('user_id')->index();
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->string('dataset', 60);
                $table->jsonb('spec')->default('{}');
                $table->boolean('is_shared')->default(false);
                $table->jsonb('shared_with')->default('[]');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'user_id', 'is_shared'], 'idx_analytics_designed_owner');
            });
        }

        if (! Schema::hasTable('analytics_export_schedules')) {
            Schema::create('analytics_export_schedules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('user_id')->index();
                $table->uuid('designed_report_id')->index();
                $table->string('frequency', 10);
                $table->unsignedSmallInteger('day_of_week')->nullable();
                $table->unsignedSmallInteger('day_of_month')->nullable();
                $table->string('format', 20)->default('csv');
                $table->string('delivery', 20)->default('email');
                $table->jsonb('recipients')->default('[]');
                $table->boolean('is_active')->default(true);
                $table->date('next_run_on')->nullable();
                $table->timestamp('last_run_at')->nullable();
                $table->string('last_status', 20)->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('designed_report_id')->references('id')->on('analytics_designed_reports')->cascadeOnDelete();
                $table->index(['tenant_id', 'is_active', 'next_run_on'], 'idx_analytics_schedules_due');
            });
        }

        if (! Schema::hasTable('analytics_export_runs')) {
            Schema::create('analytics_export_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('user_id')->index();
                $table->uuid('designed_report_id')->nullable()->index();
                $table->uuid('schedule_id')->nullable()->index();
                $table->string('dataset', 60);
                $table->string('format', 20);
                $table->unsignedInteger('row_count')->default(0);
                $table->boolean('truncated')->default(false);
                $table->string('disk', 40)->nullable();
                $table->string('path', 500)->nullable();
                $table->string('file_name', 200)->nullable();
                $table->string('sha256', 64)->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->uuid('document_id')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'created_at'], 'idx_analytics_export_runs_recent');
            });
        }

        if (! Schema::hasTable('analytics_kpi_snapshots')) {
            Schema::create('analytics_kpi_snapshots', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('kpi_key', 40);
                $table->date('snapshot_date');
                $table->date('period_from');
                $table->date('period_to');
                $table->string('value', 40)->nullable();
                $table->jsonb('details')->nullable();
                $table->timestamp('computed_at');
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'kpi_key', 'snapshot_date'], 'uq_analytics_kpi_snapshot_day');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_kpi_snapshots');
        Schema::dropIfExists('analytics_export_runs');
        Schema::dropIfExists('analytics_export_schedules');
        Schema::dropIfExists('analytics_designed_reports');
    }
};
