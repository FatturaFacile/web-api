<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Executive and role-based KPI dashboards (Mod 090): the dashboard layouts with their widgets, the threshold alert
 * rules and the log of the breaches they raised. The KPI values themselves are computed from the ledger and the
 * operational modules, never stored here. JSON columns are jsonb on PostgreSQL and text on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_dashboards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('user_id')->nullable();
            $table->string('role_code', 24);
            $table->string('title_key', 96)->nullable();
            $table->string('custom_title', 160)->nullable();
            $table->jsonb('layout_grid_json')->nullable();
            $table->unsignedInteger('refresh_interval_seconds')->default(300);
            $table->boolean('is_system_default')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'role_code', 'user_id']);
        });

        Schema::create('kpi_widgets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('dashboard_id')->constrained('kpi_dashboards')->cascadeOnDelete();
            $table->string('kpi_code', 48);
            $table->string('widget_type', 24);
            $table->string('data_source_query_name', 96);
            $table->string('default_time_comparison', 12)->default('yoy');
            $table->jsonb('threshold_rules_json')->nullable();
            $table->string('drill_down_route_template', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedSmallInteger('grid_x')->default(0);
            $table->unsignedSmallInteger('grid_y')->default(0);
            $table->unsignedSmallInteger('grid_w')->default(4);
            $table->unsignedSmallInteger('grid_h')->default(3);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'dashboard_id', 'sort_order']);
        });

        Schema::create('kpi_threshold_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('kpi_code', 48);
            $table->string('operator', 4);
            $table->decimal('warning_value', 18, 4)->nullable();
            $table->decimal('critical_value', 18, 4)->nullable();
            $table->jsonb('channels_json')->nullable();
            $table->unsignedInteger('cooldown_minutes')->default(1440);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'kpi_code']);
        });

        Schema::create('kpi_threshold_alert_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('rule_id')->constrained('kpi_threshold_rules')->cascadeOnDelete();
            $table->string('kpi_code', 48);
            $table->string('severity', 12);
            $table->decimal('observed_value', 18, 4);
            $table->decimal('threshold_value', 18, 4);
            $table->string('status', 16)->default('open');
            $table->jsonb('dispatched_channels_json')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->uuid('acknowledged_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'kpi_code', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_threshold_alert_logs');
        Schema::dropIfExists('kpi_threshold_rules');
        Schema::dropIfExists('kpi_widgets');
        Schema::dropIfExists('kpi_dashboards');
    }
};
