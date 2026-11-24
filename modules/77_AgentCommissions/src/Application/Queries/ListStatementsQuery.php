<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Queries;

final readonly class ListStatementsQuery
{
    public function __construct(
        public ?string $agentProfileId = null,
        public ?string $status = null,
        public ?int $year = null,
        public int $perPage = 25,
    ) {}
}
