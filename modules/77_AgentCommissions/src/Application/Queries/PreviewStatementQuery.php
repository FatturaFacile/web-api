<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Queries;

final readonly class PreviewStatementQuery
{
    public function __construct(
        public string $tenantId,
        public string $agentProfileId,
        public string $from,
        public string $to,
    ) {}
}
