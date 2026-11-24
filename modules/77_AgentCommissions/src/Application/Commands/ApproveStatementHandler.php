<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Commands;

use Modules\AgentCommissions\Events\StatementApprovedEvent;
use Modules\AgentCommissions\Models\Statement;
use Modules\AgentCommissions\Services\StatementService;

final class ApproveStatementHandler
{
    public function __construct(private readonly StatementService $statements) {}

    public function handle(ApproveStatementCommand $command): Statement
    {
        $statement = $this->statements->approve(Statement::query()->findOrFail($command->statementId), $command->actorId);
        event(new StatementApprovedEvent((string) $statement->id, (string) $statement->agent_profile_id, (string) $statement->tenant_id));

        return $statement;
    }
}
