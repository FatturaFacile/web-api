<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 005 - forensic extension of the audit ledger. Adds the strictly monotonic per-tenant `sequence`, the category, the
 * pseudonymous actor references, the channel/request context, the canonical `changes` document and the key version of
 * the keyed hash chain. `hash_version` 1 marks the entries written before this migration (plain SHA-256, see
 * Enterprise\AuditLedger\Actions\RecordAuditAction) so that the chain stays verifiable end to end; new entries are 2 (HMAC).
 *
 * Dialect-agnostic: no triggers. Immutability is enforced by the models; production grants INSERT and SELECT only on the
 * ledger tables to the application role (see docs/audit-ledger.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['enterprise_audit_ledger', 'enterprise_audit_ledger_archive'] as $table) {
            if (! Schema::hasColumn($table, 'sequence')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->unsignedBigInteger('sequence')->nullable();
                    $blueprint->string('category', 20)->nullable();
                    $blueprint->string('actor_ref', 80)->nullable();
                    $blueprint->string('impersonator_ref', 80)->nullable();
                    $blueprint->string('channel', 12)->nullable();
                    $blueprint->string('request_id', 64)->nullable();
                    $blueprint->json('changes')->nullable();
                    $blueprint->json('context')->nullable();
                    $blueprint->unsignedSmallInteger('key_version')->nullable();
                    $blueprint->unsignedTinyInteger('hash_version')->default(1);
                });
            }
        }

        $this->backfillSequences('enterprise_audit_ledger');

        Schema::table('enterprise_audit_ledger', function (Blueprint $table): void {
            // PostgreSQL partitions the ledger by time and only accepts unique indexes that contain the partition key:
            // there the per-tenant TransactionLock alone keeps the sequence unique.
            if (DB::getDriverName() === 'pgsql') {
                $table->index(['tenant_id', 'sequence'], 'idx_audit_ledger_tenant_sequence');
            } else {
                $table->unique(['tenant_id', 'sequence'], 'uq_audit_ledger_tenant_sequence');
            }

            $table->index(['tenant_id', 'category', 'event_timestamp'], 'idx_audit_ledger_category');
            $table->index(['tenant_id', 'actor_ref'], 'idx_audit_ledger_actor_ref');
        });

        Schema::table('enterprise_audit_ledger_archive', function (Blueprint $table): void {
            $table->index(['tenant_id', 'restore_id', 'sequence'], 'idx_audit_archive_sequence');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_audit_ledger', function (Blueprint $table): void {
            if (DB::getDriverName() === 'pgsql') {
                $table->dropIndex('idx_audit_ledger_tenant_sequence');
            } else {
                $table->dropUnique('uq_audit_ledger_tenant_sequence');
            }

            $table->dropIndex('idx_audit_ledger_category');
            $table->dropIndex('idx_audit_ledger_actor_ref');
        });

        Schema::table('enterprise_audit_ledger_archive', function (Blueprint $table): void {
            $table->dropIndex('idx_audit_archive_sequence');
        });

        foreach (['enterprise_audit_ledger', 'enterprise_audit_ledger_archive'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['sequence', 'category', 'actor_ref', 'impersonator_ref', 'channel', 'request_id', 'changes', 'context', 'key_version', 'hash_version']);
            });
        }
    }

    /**
     * Gives every entry written before this migration its place in the chain of the tenant (event time, then id: the
     * order the legacy chain was written in). The sequence is not part of the legacy record hash, so nothing is rehashed.
     */
    private function backfillSequences(string $table): void
    {
        $tenants = DB::table($table)->whereNull('sequence')->distinct()->pluck('tenant_id');

        foreach ($tenants as $tenantId) {
            $sequence = (int) DB::table($table)->where('tenant_id', $tenantId)->max('sequence');

            // Each pass takes the oldest rows still without a sequence: the updated ones leave the filter.
            while (true) {
                $rows = DB::table($table)->where('tenant_id', $tenantId)->whereNull('sequence')
                    ->orderBy('event_timestamp')->orderBy('id')
                    ->limit(500)
                    ->get(['id', 'event_timestamp']);

                if ($rows->isEmpty()) {
                    break;
                }

                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->where('event_timestamp', $row->event_timestamp)
                        ->update(['sequence' => ++$sequence]);
                }
            }
        }
    }
};
