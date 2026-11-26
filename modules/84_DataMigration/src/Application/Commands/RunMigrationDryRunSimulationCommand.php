<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Commands;

final readonly class RunMigrationDryRunSimulationCommand
{
    public function __construct(public string $jobId) {}
}
