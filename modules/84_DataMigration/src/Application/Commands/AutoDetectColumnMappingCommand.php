<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Commands;

final readonly class AutoDetectColumnMappingCommand
{
    /**
     * @param  array<string, array<string, mixed>>|null  $overrideMappingsByEntity
     * @param  array<string, mixed>|null  $overrideTransformations
     */
    public function __construct(
        public string $jobId,
        public ?string $templateId = null,
        public ?array $overrideMappingsByEntity = null,
        public ?array $overrideTransformations = null,
    ) {}
}
