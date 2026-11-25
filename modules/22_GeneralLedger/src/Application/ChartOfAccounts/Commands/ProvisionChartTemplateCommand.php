<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Commands;

final readonly class ProvisionChartTemplateCommand
{
    public function __construct(
        public string $tenantId,
        public ?string $companyId,
        public string $templateKey,
        public bool $includeDefaultMappings = true,
        public string $locale = 'it',
    ) {}
}
