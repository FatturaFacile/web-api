<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ad-hoc report builder, pivot engine and cron-scheduled multi-channel deliveries (additive on the self-service
     * reporting tables): visibility levels, pivot layout, calculated fields, conditional formatting and chart
     * configuration on the designed reports; cron expression, time zone, locale and delivery channels on the schedules;
     * status and timing on the export runs (the execution log).
     */
    public function up(): void
    {
        Schema::table('analytics_designed_reports', function (Blueprint $table): void {
            if (! Schema::hasColumn('analytics_designed_reports', 'visibility')) {
                $table->string('visibility', 20)->default('private');
                $table->jsonb('shared_role_ids')->default('[]');
                $table->jsonb('pivot')->default('{}');
                $table->jsonb('calculated_fields')->default('[]');
                $table->jsonb('conditional_formatting')->default('[]');
                $table->jsonb('chart_config')->default('{}');
            }
        });

        DB::table('analytics_designed_reports')->where('is_shared', true)->update(['visibility' => 'tenant_public']);

        Schema::table('analytics_export_schedules', function (Blueprint $table): void {
            if (! Schema::hasColumn('analytics_export_schedules', 'cron_expression')) {
                $table->string('name', 150)->nullable();
                $table->string('cron_expression', 100)->nullable();
                $table->string('timezone', 64)->default('Europe/Zurich');
                $table->string('locale', 10)->nullable();
                $table->jsonb('recipient_user_ids')->default('[]');
                $table->jsonb('external_emails')->default('[]');
                $table->jsonb('delivery_channels')->default('{}');
                $table->text('secret_config_encrypted')->nullable();
                $table->timestamp('next_run_at')->nullable();

                $table->index(['is_active', 'next_run_at'], 'idx_analytics_schedules_due_at');
            }
        });

        Schema::table('analytics_export_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('analytics_export_runs', 'status')) {
                $table->string('status', 20)->default('completed');
                $table->unsignedInteger('duration_ms')->nullable();
                $table->text('error_message')->nullable();
                $table->string('locale', 10)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('analytics_export_runs', function (Blueprint $table): void {
            $table->dropColumn(['status', 'duration_ms', 'error_message', 'locale']);
        });

        Schema::table('analytics_export_schedules', function (Blueprint $table): void {
            $table->dropIndex('idx_analytics_schedules_due_at');
            $table->dropColumn(['name', 'cron_expression', 'timezone', 'locale', 'recipient_user_ids', 'external_emails', 'delivery_channels', 'secret_config_encrypted', 'next_run_at']);
        });

        Schema::table('analytics_designed_reports', function (Blueprint $table): void {
            $table->dropColumn(['visibility', 'shared_role_ids', 'pivot', 'calculated_fields', 'conditional_formatting', 'chart_config']);
        });
    }
};
