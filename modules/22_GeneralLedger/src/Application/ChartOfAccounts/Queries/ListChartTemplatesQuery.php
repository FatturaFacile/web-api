<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Queries;

final readonly class ListChartTemplatesQuery
{
    public function __construct(
        public string $locale = 'it',
    ) {}
}
