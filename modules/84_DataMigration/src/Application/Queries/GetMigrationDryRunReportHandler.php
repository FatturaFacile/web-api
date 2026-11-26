<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Queries;

use Illuminate\Database\Eloquent\Collection;
use Modules\DataMigration\Models\MigrationJob;
use Modules\DataMigration\Models\MigrationStagingRow;
use Modules\DataMigration\Models\MigrationValidationError;

final readonly class GetMigrationDryRunReportHandler
{
    /**
     * @return array{
     *     job: MigrationJob,
     *     summary: array<string, mixed>,
     *     errors: Collection<int, MigrationValidationError>,
     *     staging_rows: Collection<int, MigrationStagingRow>
     * }
     */
    public function handle(GetMigrationDryRunReportQuery $query): array
    {
        $job = MigrationJob::query()->findOrFail($query->jobId);

        $errors = MigrationValidationError::query()
            ->where('migration_job_id', $job->id)
            ->when($query->entityType !== null, static fn ($q) => $q->where('entity_type', $query->entityType))
            ->when($query->unresolvedOnly, static fn ($q) => $q->where('is_resolved', false))
            ->orderBy('row_index')
            ->limit(250)
            ->get();

        $stagingRows = MigrationStagingRow::query()
            ->where('migration_job_id', $job->id)
            ->when($query->entityType !== null, static fn ($q) => $q->where('entity_type', $query->entityType))
            ->orderBy('row_index')
            ->limit(250)
            ->get();

        return [
            'job' => $job,
            'summary' => (array) ($job->dry_run_summary_json ?? []),
            'errors' => $errors,
            'staging_rows' => $stagingRows,
        ];
    }
}
