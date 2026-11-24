<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Queries;

use Modules\AgentCommissions\Models\Statement;

final class GetStatementHandler
{
    public function handle(GetStatementQuery $query): Statement
    {
        return Statement::query()->with(['lines', 'agent.representative:id,name,email'])->findOrFail($query->statementId);
    }
}
