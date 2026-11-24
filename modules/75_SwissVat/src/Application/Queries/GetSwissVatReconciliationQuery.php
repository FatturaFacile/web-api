<?php

declare(strict_types=1);

namespace Modules\SwissVat\Application\Queries;

final readonly class GetSwissVatReconciliationQuery
{
    public function __construct(
        public string $tenantId,
        public string $returnId,
    ) {}
}
