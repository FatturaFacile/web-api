<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that by design carry no complete set of audit stamps (same list as the audit columns architecture test).
     *
     * @var list<string>
     */
    private const EXCLUDED = [
        'migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'personal_access_tokens', 'password_reset_tokens', 'transaction_locks', 'notifications',
        'assoc_ballot_votes', 'tl_survey_responses', 'tl_survey_answers',
        'audit_logs', 'audit_worm_batches', 'audit_worm_batch_entries', 'preservation_audit_logs', 'ai_audit_trails',
        'core_byok_key_access_audit', 'core_cross_border_blocked_attempts', 'core_sovereignty_attestations',
        'offline_audit_entries', 'purchase_requisition_approval_logs',
        'mfg_mes_inbox', 'mfg_mes_oee_snapshot_revisions', 'mfg_mes_sync_changes', 'mfg_mes_sync_floors',
    ];

    /**
     * Italian VAT compliance tables created with a NOT NULL tenant_id, against the policy of a nullable tenant_id on every table.
     *
     * @var list<string>
     */
    private const NULLABLE_TENANT_TABLES = [
        'it_annual_vat_reconciliations', 'it_cu_certifications', 'it_f24_payments', 'it_lipe_communications',
        'it_third_party_goods_movements', 'it_vat_pro_rata_rates', 'it_vat_register_entries', 'it_vat_register_prints',
        'it_vat_sections', 'it_vat_ventilations',
    ];

    /**
     * Completes the six audit columns of the tables created after the previous completion migrations (Italian VAT, fixed
     * assets) and relaxes the tenant_id of the Italian VAT tables. Idempotent and additive.
     */
    public function up(): void
    {
        foreach (self::NULLABLE_TENANT_TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->uuid('tenant_id')->nullable()->change();
            });
        }

        foreach (Schema::getTableListing(schemaQualified: false) as $tableName) {
            if (in_array($tableName, self::EXCLUDED, true)) {
                continue;
            }

            $existing = Schema::getColumnListing($tableName);
            $missing = array_values(array_filter(
                ['created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by', 'deleted_by'],
                static fn (string $column): bool => ! in_array($column, $existing, true),
            ));

            if ($missing === []) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($missing): void {
                foreach ($missing as $column) {
                    if (str_ends_with($column, '_at')) {
                        $table->timestamp($column)->nullable();
                    } else {
                        $table->uuid($column)->nullable();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // Additive and nullable: dropping the columns would lose audit history.
    }
};
