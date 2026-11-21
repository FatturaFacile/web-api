<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Queries;

final readonly class ListQuotesQuery
{
    public function __construct(
        public ?string $status = null,
        public ?string $contactId = null,
        public ?string $salesRepId = null,
        public ?string $expiringBefore = null,
        public int $perPage = 25,
        public ?string $rootQuoteId = null,
    ) {}
}
