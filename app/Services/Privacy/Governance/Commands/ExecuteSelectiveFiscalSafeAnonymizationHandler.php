<?php

declare(strict_types=1);

namespace App\Services\Privacy\Governance\Commands;

use App\Services\Privacy\Governance\DsarService;

final readonly class ExecuteSelectiveFiscalSafeAnonymizationHandler
{
    public function __construct(private DsarService $dsars) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(ExecuteSelectiveFiscalSafeAnonymizationCommand $command): array
    {
        return $this->dsars->executeErasure($this->dsars->find($command->tenantId, $command->dsarId), $command->dryRun);
    }
}
