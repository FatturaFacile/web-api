<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Commands;

use Modules\DataMigration\Models\MigrationJob;
use Modules\DataMigration\Services\MigrationDryRunValidator;

final readonly class RunMigrationDryRunSimulationHandler
{
    public function __construct(private MigrationDryRunValidator $validator) {}

    /**
     * @return array{job: MigrationJob, summary: array<string, mixed>}
     */
    public function handle(RunMigrationDryRunSimulationCommand $command): array
    {
        $job = MigrationJob::query()->findOrFail($command->jobId);
        $summary = $this->validator->validate($job);

        return [
            'job' => $job->fresh() ?? $job,
            'summary' => $summary,
        ];
    }
}
