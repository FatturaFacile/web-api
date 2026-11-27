<?php

declare(strict_types=1);

namespace Modules\DataMigration\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\DataMigration\Domain\Enums\MigrationJobStatus;
use Modules\DataMigration\Models\MigrationJob;
use Modules\DataMigration\Models\MigrationStagingRow;

/**
 * 1-click atomic rollback executor: reverses every record created by a Completed migration job
 * inside a single database transaction using the deterministic rollback_manifest_json.
 */
final class MigrationRollbackExecutor
{
    /**
     * @return array{job: MigrationJob, reverted_records: int}
     */
    public function rollback(MigrationJob $job): array
    {
        if ($job->status !== MigrationJobStatus::Completed) {
            throw ValidationException::withMessages(['job' => [__('data_migration.rollback_only_completed')]]);
        }

        return DB::transaction(function () use ($job): array {
            $manifest = (array) ($job->rollback_manifest_json ?? []);
            $created = array_reverse((array) ($manifest['created_records'] ?? []));
            $reverted = 0;

            foreach ($created as $entry) {
                $modelClass = $entry['model'] ?? null;
                $id = $entry['id'] ?? null;

                if (! is_string($modelClass) || ! is_string($id) || ! class_exists($modelClass)) {
                    continue;
                }

                /** @var Model|null $record */
                $record = $modelClass::query()->whereKey($id)->first();
                if ($record === null) {
                    continue;
                }

                // Eloquent aliases forceDelete() to delete() on models without soft deletes.
                $record->forceDelete();

                $reverted++;
            }

            MigrationStagingRow::query()
                ->where('migration_job_id', $job->id)
                ->update([
                    'is_imported' => false,
                    'imported_record_id' => null,
                ]);

            $job->update([
                'status' => MigrationJobStatus::RolledBack,
                'imported_rows' => 0,
                'rolled_back_at' => now(),
            ]);

            return [
                'job' => $job->fresh() ?? $job,
                'reverted_records' => $reverted,
            ];
        });
    }
}
