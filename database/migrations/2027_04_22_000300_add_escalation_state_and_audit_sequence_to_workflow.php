<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Escalation state of a step (who it was escalated to, how many times) and a per-instance sequence on the audit
     * entries, so the hash chain has an order that does not depend on timestamps or identifiers. Both are nullable or
     * defaulted: entries written before the sequence existed stay valid and are reported as legacy by the verification.
     */
    public function up(): void
    {
        Schema::table('workflow_instance_steps', function (Blueprint $table): void {
            if (! Schema::hasColumn('workflow_instance_steps', 'escalation_level')) {
                $table->unsignedSmallInteger('escalation_level')->default(0);
            }

            if (! Schema::hasColumn('workflow_instance_steps', 'escalated_to_user_id')) {
                $table->uuid('escalated_to_user_id')->nullable();
            }

            if (! Schema::hasColumn('workflow_instance_steps', 'sla_breached_at')) {
                $table->timestampTz('sla_breached_at')->nullable();
            }
        });

        Schema::table('workflow_audit_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('workflow_audit_entries', 'sequence')) {
                $table->unsignedBigInteger('sequence')->nullable();
                $table->unique(['workflow_instance_id', 'sequence'], 'uq_wf_audit_instance_sequence');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workflow_audit_entries', function (Blueprint $table): void {
            $table->dropUnique('uq_wf_audit_instance_sequence');
            $table->dropColumn('sequence');
        });

        Schema::table('workflow_instance_steps', function (Blueprint $table): void {
            $table->dropColumn(['escalation_level', 'escalated_to_user_id', 'sla_breached_at']);
        });
    }
};
