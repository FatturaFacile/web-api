<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Queries;

final readonly class GetQuoteDetailsQuery
{
    public function __construct(
        public string $quoteId,
    ) {}
}
