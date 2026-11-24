<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Queries;

final readonly class ListLedgerQuery
{
    public function __construct(
        public ?string $agentProfileId = null,
        public ?string $status = null,
        public ?string $accrualBasis = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $search = null,
        public int $perPage = 25,
    ) {}
}
