<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

final readonly class GetNetNetMarginQuery
{
    public function __construct(
        public string $tenantId,
        public string $from,
        public string $to,
        public ?string $contractId,
        public ?string $contactId,
        public string $groupBy = 'product',
    ) {}
}
