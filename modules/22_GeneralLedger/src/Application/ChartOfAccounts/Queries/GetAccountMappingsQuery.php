<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Queries;

final readonly class GetAccountMappingsQuery
{
    public function __construct(
        public string $tenantId,
        public ?string $sourceAccountId = null,
        public ?string $sourceStandard = null,
        public ?string $targetStandard = null,
        public ?bool $isActive = null,
    ) {}
}
