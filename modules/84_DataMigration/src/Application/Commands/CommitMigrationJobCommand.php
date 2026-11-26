<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Commands;

final readonly class CommitMigrationJobCommand
{
    public function __construct(public string $jobId) {}
}
