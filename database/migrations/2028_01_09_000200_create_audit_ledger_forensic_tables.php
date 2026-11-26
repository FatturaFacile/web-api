<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 005 - checkpoints (daily and accounting period), integrity verification runs, the pseudonym map used for the right
 * to erasure, the signed export packages and the per-tenant key version activations of the audit ledger.
 * `tenant_id` is nullable on every table by the tenant policy; the audit code always writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_checkpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('type', 12);
            $table->string('period_ref', 40)->nullable();
            $table->unsignedBigInteger('sequence');
            $table->char('hash', 64);
            $table->char('signature', 64);
            $table->unsignedSmallInteger('key_version');
            $table->timestampTz('created_at');
            $table->timestampTz('anchored_at')->nullable();
            $table->string('anchor_uri', 500)->nullable();

            $table->index(['tenant_id', 'type', 'created_at'], 'idx_audit_checkpoints_tenant_type');
            $table->unique(['tenant_id', 'type', 'period_ref'], 'uq_audit_checkpoints_period');
        });

        Schema::create('audit_integrity_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('trigger', 12);
            $table->string('status', 12);
            $table->string('outcome', 16)->nullable();
            $table->unsignedBigInteger('broken_at_sequence')->nullable();
            $table->unsignedBigInteger('missing_from_sequence')->nullable();
            $table->unsignedBigInteger('missing_to_sequence')->nullable();
            $table->unsignedBigInteger('verified_entries')->default(0);
            $table->unsignedBigInteger('last_sequence')->nullable();
            $table->uuid('requested_by')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status'], 'idx_audit_runs_tenant_status');
            $table->index(['tenant_id', 'created_at'], 'idx_audit_runs_tenant_created');
        });

        Schema::create('audit_subject_map', function (Blueprint $table): void {
            $table->string('token', 64)->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('subject_type', 120);
            $table->string('subject_id', 64);
            $table->text('display_name');
            $table->timestampTz('created_at');

            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'idx_audit_subject_map_subject');
        });

        Schema::create('audit_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('requested_by')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->string('status', 12);
            $table->date('from_date');
            $table->date('to_date');
            $table->json('filters')->nullable();
            $table->unsignedBigInteger('from_sequence')->nullable();
            $table->unsignedBigInteger('to_sequence')->nullable();
            $table->char('start_hash', 64)->nullable();
            $table->char('end_hash', 64)->nullable();
            $table->unsignedBigInteger('entries_count')->nullable();
            $table->string('verification_outcome', 16)->nullable();
            $table->string('disk', 40)->nullable();
            $table->string('path', 500)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('signature', 128)->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at'], 'idx_audit_exports_tenant_created');
            $table->unique(['tenant_id', 'idempotency_key'], 'uq_audit_exports_idempotency');
        });

        Schema::create('audit_key_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->unsignedSmallInteger('key_version');
            $table->uuid('activated_by')->nullable();
            $table->timestampTz('activated_at');

            $table->unique(['tenant_id', 'key_version'], 'uq_audit_key_versions_tenant_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_key_versions');
        Schema::dropIfExists('audit_exports');
        Schema::dropIfExists('audit_subject_map');
        Schema::dropIfExists('audit_integrity_runs');
        Schema::dropIfExists('audit_checkpoints');
    }
};
