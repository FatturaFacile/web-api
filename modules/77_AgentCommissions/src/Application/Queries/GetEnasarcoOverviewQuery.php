<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Queries;

final readonly class GetEnasarcoOverviewQuery
{
    public function __construct(
        public string $tenantId,
        public int $year,
        public ?string $agentProfileId = null,
    ) {}
}
