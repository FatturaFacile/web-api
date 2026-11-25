<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Queries;

final readonly class GetAccountDetailQuery
{
    public function __construct(
        public string $tenantId,
        public string $accountId,
    ) {}
}
