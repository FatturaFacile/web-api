<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Queries;

final readonly class ListProposalsQuery
{
    public function __construct(
        public string $tenantId,
        /** @var array<string, mixed> */
        public array $filters,
        public string $asOf,
    ) {}
}
