<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

final readonly class GetSellOutReportsQuery
{
    public function __construct(
        public ?string $contractId,
        public ?string $deliveryPointId,
        public ?string $from,
        public ?string $to,
        public int $perPage = 50,
    ) {}
}
