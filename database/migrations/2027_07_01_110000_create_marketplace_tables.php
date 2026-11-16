<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marketplace of connectors: the installations of a catalog connector by a company (settings in clear, credentials
     * encrypted by the model cast), the health checks and the sync runs they leave behind. The catalog itself is code (the
     * connector registry), not a table. New tables only; the lifecycle and the rate limits are enforced by the application.
     */
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_installations')) {
            Schema::create('marketplace_installations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('connector_key', 60);
                $table->string('connector_version', 20);
                $table->string('name', 120);
                $table->string('status', 30)->default('pending_configuration');
                $table->json('settings')->nullable();
                $table->text('credentials')->nullable();
                $table->json('granted_scopes');
                $table->unsignedInteger('rate_limit_per_minute')->nullable();
                $table->timestamp('configured_at')->nullable();
                $table->timestamp('enabled_at')->nullable();
                $table->timestamp('disabled_at')->nullable();
                $table->string('last_health_status', 20)->default('unknown');
                $table->timestamp('last_health_checked_at')->nullable();
                $table->string('last_health_message', 255)->nullable();
                $table->timestamp('last_sync_at')->nullable();
                $table->uuid('installed_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'connector_key']);
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('marketplace_sync_runs')) {
            Schema::create('marketplace_sync_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('installation_id')->constrained('marketplace_installations')->cascadeOnDelete();
                $table->string('resource', 60);
                $table->string('trigger', 20)->default('manual');
                $table->string('status', 20)->default('running');
                $table->timestamp('started_at');
                $table->timestamp('finished_at')->nullable();
                $table->unsignedInteger('records_processed')->default(0);
                $table->unsignedInteger('records_failed')->default(0);
                $table->string('message', 500)->nullable();
                $table->uuid('triggered_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'installation_id', 'started_at'], 'marketplace_sync_runs_installation');
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('marketplace_health_checks')) {
            Schema::create('marketplace_health_checks', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('installation_id')->constrained('marketplace_installations')->cascadeOnDelete();
                $table->string('status', 20);
                $table->unsignedInteger('latency_ms')->nullable();
                $table->string('message', 255)->nullable();
                $table->timestamp('checked_at');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'installation_id', 'checked_at'], 'marketplace_health_installation');
            });
        }
    }

    public function down(): void
    {
        foreach (['marketplace_health_checks', 'marketplace_sync_runs', 'marketplace_installations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
