<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

final readonly class GetDeliveryPointsQuery
{
    public function __construct(
        public ?string $contactId,
        public ?string $search,
        public int $perPage = 100,
    ) {}
}
