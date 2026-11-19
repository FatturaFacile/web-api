<?php

declare(strict_types=1);

namespace App\Services\Privacy\Governance\Commands;

use App\Models\Privacy\PrivacyRetentionRunLog;
use App\Services\Privacy\Governance\RetentionPurgeService;

final readonly class RunAutomatedRetentionPurgeHandler
{
    public function __construct(private RetentionPurgeService $purge) {}

    public function handle(RunAutomatedRetentionPurgeCommand $command): PrivacyRetentionRunLog
    {
        return $this->purge->runForTenant($command->tenantId, $command->dryRun);
    }
}
