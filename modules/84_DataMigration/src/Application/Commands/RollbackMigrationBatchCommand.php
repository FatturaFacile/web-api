<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Commands;

final readonly class RollbackMigrationBatchCommand
{
    public function __construct(public string $jobId) {}
}
