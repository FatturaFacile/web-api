<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

final readonly class GetContractDetailQuery
{
    public function __construct(
        public string $contractId,
    ) {}
}
