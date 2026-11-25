<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Queries;

final readonly class ExportChartOfAccountsQuery
{
    public function __construct(
        public string $tenantId,
        public string $format = 'xlsx',
        public ?string $templateCode = null,
        public ?bool $isActive = null,
    ) {}
}
