<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'workflow_nodes',
            'workflow_transitions',
            'approval_matrixes',
            'approval_matrix_rules',
            'approval_matrix_tiers',
            'workflow_escalation_rules',
            'workflow_instance_steps',
            'approval_decisions',
            'workflow_comments',
            'business_calendars',
            'business_calendar_holidays',
            'approval_tokens',
            'workflow_audit_entries',
            'workflow_webhook_subscriptions',
            'workflow_outbound_events',
            'workflow_delivery_attempts',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->softDeletesTz();
                });
            }
        }
    }

    public function down(): void
    {
        $tables = [
            'workflow_nodes',
            'workflow_transitions',
            'approval_matrixes',
            'approval_matrix_rules',
            'approval_matrix_tiers',
            'workflow_escalation_rules',
            'workflow_instance_steps',
            'approval_decisions',
            'workflow_comments',
            'business_calendars',
            'business_calendar_holidays',
            'approval_tokens',
            'workflow_audit_entries',
            'workflow_webhook_subscriptions',
            'workflow_outbound_events',
            'workflow_delivery_attempts',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->dropSoftDeletesTz();
                });
            }
        }
    }
};
