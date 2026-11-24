<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Framework and infrastructure tables that carry no business records.
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
     * Every business table gets created_at, updated_at, deleted_at and the nullable created_by, updated_by and
     * deleted_by columns that App\Support\Auditing\AuditStamper fills from the authenticated user.
     */
    public function up(): void
    {
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
        // The columns are additive and nullable: dropping them would lose audit history.
    }
};
