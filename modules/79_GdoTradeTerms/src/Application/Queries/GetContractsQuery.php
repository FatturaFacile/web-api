<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

final readonly class GetContractsQuery
{
    public function __construct(
        public ?string $status,
        public ?string $contactId,
        public ?string $jurisdiction,
        public ?string $search,
        public int $perPage = 25,
    ) {}
}
