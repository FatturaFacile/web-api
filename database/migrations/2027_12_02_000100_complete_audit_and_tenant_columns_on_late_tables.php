<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Framework and infrastructure tables that carry no business records (same list as the audit columns migration).
     *
     * @var list<string>
     */
    private const EXCLUDED = [
        'migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'personal_access_tokens', 'password_reset_tokens', 'transaction_locks', 'notifications',
        // Secret ballot: a vote must carry no user, no timestamp and nothing else that could link it to its voter.
        'assoc_ballot_votes',
        // Anonymous engagement survey: a response and its answers must not name or time-stamp who answered.
        'tl_survey_responses', 'tl_survey_answers',
    ];

    /**
     * Tables created with a NOT NULL tenant_id, against the policy of a nullable tenant_id on every table.
     *
     * @var list<string>
     */
    private const NULLABLE_TENANT_TABLES = ['esm_digital_service_reports', 'vat_settlement_periods'];

    /**
     * Tables created after the audit columns migration miss some of the six audit columns, and two tables have a
     * NOT NULL tenant_id. This migration is idempotent and completes both.
     */
    public function up(): void
    {
        // The audit migration gave every table the audit columns, including the votes of secret ballots: the creator of a
        // vote is its voter. Remove them again so that the vote table keeps no link between voter and choice.
        if (Schema::hasTable('assoc_ballot_votes')) {
            $columns = array_values(array_intersect(
                ['created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by', 'deleted_by'],
                Schema::getColumnListing('assoc_ballot_votes'),
            ));

            if ($columns !== []) {
                Schema::table('assoc_ballot_votes', function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }
        }

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
