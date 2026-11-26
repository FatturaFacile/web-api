<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Commands;

use Illuminate\Support\Facades\DB;
use Modules\DataMigration\Models\MigrationJob;
use Modules\DataMigration\Models\MigrationStagingRow;
use Modules\DataMigration\Models\MigrationValidationError;

final readonly class PatchMigrationStagingRowHandler
{
    public function handle(PatchMigrationStagingRowCommand $command): MigrationStagingRow
    {
        return DB::transaction(function () use ($command): MigrationStagingRow {
            $job = MigrationJob::query()->findOrFail($command->jobId);
            $row = MigrationStagingRow::query()
                ->where('migration_job_id', $job->id)
                ->findOrFail($command->rowId);

            $raw = (array) $row->raw_payload_json;
            $mapped = (array) ($row->mapped_payload_json ?? []);
            $mappings = (array) ($job->source_metadata_json['mappings_by_entity'][$row->entity_type] ?? []);

            foreach ($command->values as $field => $newValue) {
                $mapped[$field] = $newValue;

                $sourceCol = $mappings[$field]['source_column'] ?? null;
                if (is_string($sourceCol) && $sourceCol !== '') {
                    $raw[$sourceCol] = $newValue;
                } else {
                    $raw[$field] = $newValue;
                }
            }

            $row->update([
                'raw_payload_json' => $raw,
                'mapped_payload_json' => $mapped,
            ]);

            MigrationValidationError::query()
                ->where('migration_job_id', $job->id)
                ->where('entity_type', $row->entity_type)
                ->where('row_index', $row->row_index)
                ->whereIn('field_name', array_keys($command->values))
                ->update(['is_resolved' => true]);

            return $row->fresh() ?? $row;
        });
    }
}
