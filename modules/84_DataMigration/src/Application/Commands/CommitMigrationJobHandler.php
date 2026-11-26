<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Commands;

use Modules\DataMigration\Models\MigrationJob;
use Modules\DataMigration\Services\MigrationCommitExecutor;

final readonly class CommitMigrationJobHandler
{
    public function __construct(private MigrationCommitExecutor $executor) {}

    /**
     * @return array{job: MigrationJob, imported_rows: int, manifest_entries: int}
     */
    public function handle(CommitMigrationJobCommand $command): array
    {
        $job = MigrationJob::query()->findOrFail($command->jobId);

        return $this->executor->commit($job);
    }
}
