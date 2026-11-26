<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Commands;

final readonly class PatchMigrationStagingRowCommand
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public string $jobId,
        public string $rowId,
        public array $values,
    ) {}
}
