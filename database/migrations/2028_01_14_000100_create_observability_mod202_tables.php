<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #448 (Mod 202) — enterprise observability: the slow query / N+1 captures of the SQL profiler (sanitized text, never the bindings),
 * the per-request endpoint samples, the settings of the platform, and the synthetic probes of the CH/IT institutional connectors with their
 * rolling status. The data is platform diagnostics: tenant_id is only the tenant that triggered a capture (nullable, no foreign key, so a
 * deleted company never blocks the retention job). Dialect-agnostic (SQLite and PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('observability_slow_queries')) {
            Schema::create('observability_slow_queries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->string('kind', 12)->default('slow');
                $table->string('fingerprint', 40);
                $table->text('sql_text');
                $table->decimal('duration_ms', 12, 3);
                $table->unsignedInteger('execution_count')->default(1);
                $table->string('connection_name', 60)->nullable();
                $table->string('trace_id', 32)->nullable();
                $table->string('origin_type', 12)->nullable();
                $table->string('origin', 190)->nullable();
                $table->string('plan_status', 16)->default('skipped');
                $table->json('plan')->nullable();
                $table->json('suggested_indexes')->nullable();
                $table->timestamp('captured_at');
                $table->timestamps();

                $table->index(['tenant_id', 'captured_at'], 'obs_slow_tenant_captured_idx');
                $table->index(['captured_at', 'fingerprint'], 'obs_slow_captured_fp_idx');
            });
        }

        if (! Schema::hasTable('observability_endpoint_samples')) {
            Schema::create('observability_endpoint_samples', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->string('method', 10);
                $table->string('route', 190);
                $table->unsignedSmallInteger('status_code');
                $table->decimal('duration_ms', 12, 3);
                $table->unsignedInteger('db_query_count')->default(0);
                $table->decimal('db_time_ms', 12, 3)->default(0);
                $table->string('trace_id', 32)->nullable();
                $table->timestamp('captured_at');

                $table->index(['tenant_id', 'captured_at'], 'obs_endpoint_tenant_captured_idx');
                $table->index(['captured_at', 'route'], 'obs_endpoint_captured_route_idx');
            });
        }

        if (! Schema::hasTable('observability_settings')) {
            Schema::create('observability_settings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->string('key', 60);
                $table->json('value');
                $table->timestamps();

                $table->unique(['key'], 'obs_settings_key_unique');
                $table->index(['tenant_id', 'key'], 'obs_settings_tenant_key_idx');
            });
        }

        if (! Schema::hasTable('observability_connector_checks')) {
            Schema::create('observability_connector_checks', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->string('connector_key', 40);
                $table->boolean('ok');
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->unsignedInteger('latency_ms')->nullable();
                $table->integer('tls_days_remaining')->nullable();
                $table->boolean('tls_valid')->nullable();
                $table->string('error', 255)->nullable();
                // Microsecond precision: the probes of one connector are ordered by this column.
                $table->timestamp('checked_at', 6);

                $table->index(['tenant_id', 'checked_at'], 'obs_checks_tenant_checked_idx');
                $table->index(['connector_key', 'checked_at'], 'obs_checks_key_checked_idx');
            });
        }

        if (! Schema::hasTable('observability_connector_statuses')) {
            Schema::create('observability_connector_statuses', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->string('connector_key', 40);
                $table->string('status', 16)->default('operational');
                $table->boolean('maintenance')->default(false);
                $table->string('maintenance_message', 255)->nullable();
                $table->decimal('uptime_24h', 5, 2)->default(100);
                $table->decimal('uptime_7d', 5, 2)->default(100);
                $table->unsignedInteger('avg_latency_ms')->nullable();
                $table->unsignedInteger('last_latency_ms')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->string('last_error', 255)->nullable();
                $table->integer('tls_days_remaining')->nullable();
                $table->timestamp('status_changed_at')->nullable();
                $table->timestamps();

                $table->unique(['connector_key'], 'obs_status_key_unique');
                $table->index(['tenant_id', 'connector_key'], 'obs_status_tenant_key_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['observability_connector_statuses', 'observability_connector_checks', 'observability_settings', 'observability_endpoint_samples', 'observability_slow_queries'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
