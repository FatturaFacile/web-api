<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

final readonly class GetAccrualRunsQuery
{
    public function __construct(
        public ?string $status,
        public int $perPage = 25,
    ) {}
}
