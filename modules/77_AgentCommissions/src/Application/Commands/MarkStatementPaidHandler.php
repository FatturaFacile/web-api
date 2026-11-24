<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Commands;

use Modules\AgentCommissions\Events\StatementPaidEvent;
use Modules\AgentCommissions\Models\Statement;
use Modules\AgentCommissions\Services\StatementService;

final class MarkStatementPaidHandler
{
    public function __construct(private readonly StatementService $statements) {}

    public function handle(MarkStatementPaidCommand $command): Statement
    {
        $statement = $this->statements->markPaid(Statement::query()->findOrFail($command->statementId), $command->reference, $command->paidOn);
        event(new StatementPaidEvent((string) $statement->id, (string) $statement->agent_profile_id, (string) $statement->tenant_id));

        return $statement;
    }
}
