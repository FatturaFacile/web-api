<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Queries;

final readonly class ListAccountsQuery
{
    public function __construct(
        public string $tenantId,
        public ?string $accountType = null,
        public ?bool $isActive = null,
        public ?bool $isPostable = null,
        public ?string $templateCode = null,
        public ?string $search = null,
        public int $perPage = 100,
    ) {}
}
