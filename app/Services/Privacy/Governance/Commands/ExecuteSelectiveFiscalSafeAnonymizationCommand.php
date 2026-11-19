<?php

declare(strict_types=1);

namespace App\Services\Privacy\Governance\Commands;

final readonly class ExecuteSelectiveFiscalSafeAnonymizationCommand
{
    public function __construct(public string $tenantId, public string $dsarId, public bool $dryRun = false) {}
}
