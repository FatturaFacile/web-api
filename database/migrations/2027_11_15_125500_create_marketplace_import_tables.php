<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records pulled from the external systems: the sync cursors of an installation, the staging table the mappers write
     * to (one row per external record, unique by idempotency key so a re-sync never duplicates) and the failures of single
     * records of a run.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('marketplace_installations', 'sync_cursors')) {
            Schema::table('marketplace_installations', function (Blueprint $table): void {
                $table->json('sync_cursors')->nullable();
            });
        }

        if (! Schema::hasTable('marketplace_staged_records')) {
            Schema::create('marketplace_staged_records', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('installation_id')->constrained('marketplace_installations')->cascadeOnDelete();
                $table->foreignUuid('sync_run_id')->nullable()->constrained('marketplace_sync_runs')->nullOnDelete();
                $table->string('connector_key', 60);
                $table->string('resource', 60);
                $table->string('entity_type', 30);
                $table->string('external_id', 191);
                $table->string('idempotency_key', 64);
                $table->string('content_hash', 64);
                $table->json('payload');
                $table->string('status', 20)->default('pending');
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['installation_id', 'idempotency_key'], 'marketplace_staged_idempotency');
                $table->index(['tenant_id', 'entity_type', 'status'], 'marketplace_staged_entity_status');
            });
        }

        if (! Schema::hasTable('marketplace_sync_failures')) {
            Schema::create('marketplace_sync_failures', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('installation_id')->constrained('marketplace_installations')->cascadeOnDelete();
                $table->foreignUuid('sync_run_id')->constrained('marketplace_sync_runs')->cascadeOnDelete();
                $table->string('resource', 60);
                $table->string('external_id', 191)->nullable();
                $table->string('message', 500);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'sync_run_id'], 'marketplace_failures_run');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_sync_failures');
        Schema::dropIfExists('marketplace_staged_records');

        if (Schema::hasColumn('marketplace_installations', 'sync_cursors')) {
            Schema::table('marketplace_installations', function (Blueprint $table): void {
                $table->dropColumn('sync_cursors');
            });
        }
    }
};
