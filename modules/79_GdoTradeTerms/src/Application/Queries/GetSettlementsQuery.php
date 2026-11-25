<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

final readonly class GetSettlementsQuery
{
    public function __construct(
        public ?string $status,
        public ?string $contractId,
        public int $perPage = 25,
    ) {}
}
