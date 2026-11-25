<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

final readonly class GetSettlementDetailQuery
{
    public function __construct(
        public string $settlementId,
    ) {}
}
