<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

final readonly class GetContractProgressQuery
{
    public function __construct(
        public string $contractId,
        public ?string $asOf,
    ) {}
}
