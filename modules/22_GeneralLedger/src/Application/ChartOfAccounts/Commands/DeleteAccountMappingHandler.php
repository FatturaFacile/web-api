<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Commands;

use Modules\GeneralLedger\Models\AccountMapping;

final readonly class DeleteAccountMappingHandler
{
    public function handle(DeleteAccountMappingCommand $command): void
    {
        /** @var AccountMapping $mapping */
        $mapping = AccountMapping::withoutGlobalScopes()
            ->where('tenant_id', $command->tenantId)
            ->findOrFail($command->mappingId);

        $mapping->delete();
    }
}
