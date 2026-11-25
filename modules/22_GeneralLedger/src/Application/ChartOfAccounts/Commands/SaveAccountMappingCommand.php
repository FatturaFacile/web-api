<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Commands;

final readonly class SaveAccountMappingCommand
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $tenantId,
        public ?string $companyId,
        public array $attributes,
        public ?string $mappingId = null,
    ) {}
}
