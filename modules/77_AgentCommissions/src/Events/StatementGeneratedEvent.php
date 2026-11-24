<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Events;

/**
 * A commission statement was generated: a plain Laravel event that listeners may use to notify the agent or post to the ledger.
 */
final readonly class StatementGeneratedEvent
{
    public function __construct(
        public string $statementId,
        public string $agentProfileId,
        public string $tenantId,
    ) {}
}
