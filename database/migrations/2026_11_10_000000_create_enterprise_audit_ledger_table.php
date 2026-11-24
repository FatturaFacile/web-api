<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enterprise_audit_ledger')) {
            $driver = DB::getDriverName();

            if ($driver === 'pgsql') {
                DB::statement('CREATE EXTENSION IF NOT EXISTS "uuid-ossp";');

                DB::statement("
                    CREATE TABLE enterprise_audit_ledger (
                        id UUID NOT NULL DEFAULT gen_random_uuid(),
                        tenant_id UUID NOT NULL,
                        company_id UUID NOT NULL,
                        event_timestamp TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        module_code VARCHAR(30) NOT NULL,
                        action VARCHAR(50) NOT NULL,
                        resource_type VARCHAR(60) NOT NULL,
                        resource_id UUID NOT NULL,
                        actor_user_id UUID,
                        actor_type VARCHAR(20) NOT NULL DEFAULT 'user',
                        payload_before JSONB,
                        payload_after JSONB,
                        diff_json JSONB,
                        ip_address INET,
                        user_agent TEXT,
                        record_hash VARCHAR(64) NOT NULL,
                        previous_hash VARCHAR(64) NOT NULL,
                        PRIMARY KEY (id, event_timestamp)
                    ) PARTITION BY RANGE (event_timestamp);
                ");

                // Execute partitions and indexes via unprepared for multi-command support
                DB::unprepared("
                    CREATE TABLE IF NOT EXISTS enterprise_audit_ledger_default PARTITION OF enterprise_audit_ledger DEFAULT;
                    CREATE TABLE IF NOT EXISTS enterprise_audit_ledger_y2026m01 PARTITION OF enterprise_audit_ledger
                        FOR VALUES FROM ('2026-01-01') TO ('2026-02-01');
                    CREATE TABLE IF NOT EXISTS enterprise_audit_ledger_y2026m02 PARTITION OF enterprise_audit_ledger
                        FOR VALUES FROM ('2026-02-01') TO ('2026-03-01');
                    CREATE TABLE IF NOT EXISTS enterprise_audit_ledger_y2026m03 PARTITION OF enterprise_audit_ledger
                        FOR VALUES FROM ('2026-03-01') TO ('2026-04-01');

                    CREATE INDEX IF NOT EXISTS idx_audit_resource ON enterprise_audit_ledger (tenant_id, resource_type, resource_id, event_timestamp DESC);
                    CREATE INDEX IF NOT EXISTS idx_audit_actor ON enterprise_audit_ledger (tenant_id, actor_user_id, event_timestamp DESC);
                ");
            } else {
                Schema::create('enterprise_audit_ledger', function (Blueprint $table): void {
                    $table->uuid('id');
                    $table->uuid('tenant_id');
                    $table->uuid('company_id');
                    $table->timestampTz('event_timestamp')->useCurrent();
                    $table->string('module_code', 30);
                    $table->string('action', 50);
                    $table->string('resource_type', 60);
                    $table->uuid('resource_id');
                    $table->uuid('actor_user_id')->nullable();
                    $table->string('actor_type', 20)->default('user');
                    $table->json('payload_before')->nullable();
                    $table->json('payload_after')->nullable();
                    $table->json('diff_json')->nullable();
                    $table->string('ip_address', 45)->nullable();
                    $table->text('user_agent')->nullable();
                    $table->string('record_hash', 64);
                    $table->string('previous_hash', 64);

                    $table->primary(['id', 'event_timestamp']);
                    $table->index(['tenant_id', 'resource_type', 'resource_id', 'event_timestamp'], 'idx_audit_resource');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_audit_ledger');
    }
};
