<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Commands;

final readonly class DeleteAccountMappingCommand
{
    public function __construct(
        public string $tenantId,
        public string $mappingId,
    ) {}
}
