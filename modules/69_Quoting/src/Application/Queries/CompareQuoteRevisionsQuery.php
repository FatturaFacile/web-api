<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Queries;

final readonly class CompareQuoteRevisionsQuery
{
    public function __construct(
        public string $fromQuoteId,
        public string $toQuoteId,
    ) {}
}
