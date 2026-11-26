<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Commands;

use Modules\DataMigration\Domain\Enums\MigrationSourceSystem;

final readonly class UploadMigrationSourceArchiveCommand
{
    /**
     * @param  list<string>  $targetEntities
     */
    public function __construct(
        public string $tenantId,
        public ?string $legalEntityId,
        public ?string $userId,
        public MigrationSourceSystem $sourceSystem,
        public array $targetEntities,
        public string $filePath,
        public string $originalFileName,
        public string $extension,
        public ?string $templateId = null,
    ) {}
}
