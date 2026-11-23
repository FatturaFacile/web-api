<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Framework and infrastructure tables that carry no business records (same list as the earlier audit migrations).
     *
     * @var list<string>
     */
    private const EXCLUDED = [
        'migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'personal_access_tokens', 'password_reset_tokens', 'transaction_locks', 'notifications',
        'assoc_ballot_votes',
        'tl_survey_responses', 'tl_survey_answers',
    ];

    /**
     * Tables created with a NOT NULL tenant_id, against the policy of a nullable tenant_id on every table.
     *
     * @var list<string>
     */
    private const NULLABLE_TENANT_TABLES = [
        'customer_credit_limits', 'customer_credit_overrides', 'customer_statement_dispatches', 'esm_work_order_photos',
        'project_wbs_baselines', 'project_wbs_dependencies', 'project_wbs_nodes', 'receivable_allocations',
        'receivable_items', 'receivable_provision_rates',
    ];

    /**
     * Tables created after the previous completion migration miss some of the six audit columns and carry a NOT NULL
     * tenant_id. Idempotent and additive.
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
