<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Queries;

final readonly class GetAccountTreeQuery
{
    public function __construct(
        public string $tenantId,
        public ?string $templateCode = null,
        public ?string $accountType = null,
        public ?bool $isActive = null,
        public ?string $search = null,
        public string $locale = 'it',
    ) {}
}
