<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Commands;

use Modules\DataMigration\Models\MigrationJob;
use Modules\DataMigration\Services\MigrationRollbackExecutor;

final readonly class RollbackMigrationBatchHandler
{
    public function __construct(private MigrationRollbackExecutor $executor) {}

    /**
     * @return array{job: MigrationJob, reverted_records: int}
     */
    public function handle(RollbackMigrationBatchCommand $command): array
    {
        $job = MigrationJob::query()->findOrFail($command->jobId);

        return $this->executor->rollback($job);
    }
}
