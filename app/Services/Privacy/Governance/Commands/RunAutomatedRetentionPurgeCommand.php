<?php

declare(strict_types=1);

namespace App\Services\Privacy\Governance\Commands;

final readonly class RunAutomatedRetentionPurgeCommand
{
    public function __construct(public string $tenantId, public bool $dryRun = false) {}
}
