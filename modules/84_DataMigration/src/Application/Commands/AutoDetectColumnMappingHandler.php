<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Commands;

use Modules\DataMigration\Domain\Enums\MigrationJobStatus;
use Modules\DataMigration\Models\MigrationJob;
use Modules\DataMigration\Services\SmartColumnMappingDetector;

final readonly class AutoDetectColumnMappingHandler
{
    public function __construct(private SmartColumnMappingDetector $detector) {}

    public function handle(AutoDetectColumnMappingCommand $command): MigrationJob
    {
        $job = MigrationJob::query()->findOrFail($command->jobId);
        $metadata = (array) ($job->source_metadata_json ?? []);

        $detected = $this->detector->detect(
            $job->source_system,
            (array) $job->target_entities,
            (array) ($metadata['headers'] ?? []),
            (array) ($metadata['headers_by_entity'] ?? []),
            (array) ($metadata['sample'] ?? []),
            $command->templateId ?? ($metadata['matched_template_id'] ?? null),
        );

        $mappings = $command->overrideMappingsByEntity !== null && $command->overrideMappingsByEntity !== []
            ? $command->overrideMappingsByEntity
            : $detected['mappings_by_entity'];

        $transformations = $command->overrideTransformations !== null && $command->overrideTransformations !== []
            ? array_merge($detected['value_transformations'], $command->overrideTransformations)
            : $detected['value_transformations'];

        $metadata['matched_template_id'] = $detected['matched_template_id'];
        $metadata['mappings_by_entity'] = $mappings;
        $metadata['value_transformations'] = $transformations;

        $job->update([
            'source_metadata_json' => $metadata,
            'status' => MigrationJobStatus::Mapped,
            'dry_run_passed' => false,
        ]);

        return $job->fresh() ?? $job;
    }
}
