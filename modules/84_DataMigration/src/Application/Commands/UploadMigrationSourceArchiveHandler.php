<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Commands;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\DataMigration\Domain\Enums\MigrationJobStatus;
use Modules\DataMigration\Models\MigrationJob;
use Modules\DataMigration\Models\MigrationStagingRow;
use Modules\DataMigration\Services\MigrationArchiveReader;
use Modules\DataMigration\Services\SmartColumnMappingDetector;

final readonly class UploadMigrationSourceArchiveHandler
{
    public function __construct(
        private MigrationArchiveReader $reader,
        private SmartColumnMappingDetector $detector,
    ) {}

    public function handle(UploadMigrationSourceArchiveCommand $command): MigrationJob
    {
        $tenant = Tenant::query()->whereKey($command->tenantId)->first();
        $parsed = $this->reader->read(
            $command->filePath,
            $command->extension,
            $command->sourceSystem,
            $command->targetEntities,
            $tenant,
        );

        $storedPath = 'data-migration/'.$command->tenantId.'/'.Str::uuid7().'.'.strtolower($command->extension);
        $rawContent = file_get_contents($command->filePath);
        if ($rawContent !== false) {
            Storage::disk('local')->put($storedPath, $rawContent);
        }

        $detected = $this->detector->detect(
            $command->sourceSystem,
            $command->targetEntities,
            $parsed['headers'],
            $parsed['headers_by_entity'],
            $parsed['sample'],
            $command->templateId,
        );

        return DB::transaction(function () use ($command, $parsed, $storedPath, $detected): MigrationJob {
            $job = MigrationJob::query()->create([
                'tenant_id' => $command->tenantId,
                'legal_entity_id' => $command->legalEntityId,
                'created_by_user_id' => $command->userId,
                'source_system' => $command->sourceSystem,
                'target_entities' => $command->targetEntities,
                'status' => MigrationJobStatus::Uploaded,
                'dry_run_passed' => false,
                'total_rows' => count($parsed['rows']),
                'imported_rows' => 0,
                'error_rows' => 0,
                'source_file_path' => $storedPath,
                'source_metadata_json' => [
                    'file_name' => $command->originalFileName,
                    'extension' => strtolower($command->extension),
                    'headers' => $parsed['headers'],
                    'headers_by_entity' => $parsed['headers_by_entity'],
                    'sample' => $parsed['sample'],
                    'parser_metadata' => $parsed['metadata'],
                    'matched_template_id' => $detected['matched_template_id'],
                    'mappings_by_entity' => $detected['mappings_by_entity'],
                    'value_transformations' => $detected['value_transformations'],
                ],
            ]);

            $now = now();
            $stagingInsert = [];
            foreach ($parsed['rows'] as $row) {
                $stagingInsert[] = [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $command->tenantId,
                    'migration_job_id' => $job->id,
                    'row_index' => $row['row_index'],
                    'entity_type' => $row['entity_type'],
                    'source_record_identifier' => $row['source_record_identifier'],
                    'raw_payload_json' => json_encode($row['raw_payload'], JSON_THROW_ON_ERROR),
                    'mapped_payload_json' => null,
                    'is_valid' => false,
                    'is_imported' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($stagingInsert, 200) as $chunk) {
                MigrationStagingRow::query()->insert($chunk);
            }

            return $job->fresh() ?? $job;
        });
    }
}
