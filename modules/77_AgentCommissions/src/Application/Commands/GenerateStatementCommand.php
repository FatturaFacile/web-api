<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Commands;

final readonly class GenerateStatementCommand
{
    public function __construct(
        public string $tenantId,
        public string $agentProfileId,
        public string $from,
        public string $to,
        public string $periodType,
        public ?string $actorId = null,
    ) {}
}
