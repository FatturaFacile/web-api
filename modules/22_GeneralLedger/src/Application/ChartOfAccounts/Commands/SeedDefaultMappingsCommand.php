<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Commands;

final readonly class SeedDefaultMappingsCommand
{
    public function __construct(
        public string $tenantId,
        public ?string $companyId,
        public ?string $sourceStandard = null,
    ) {}
}
