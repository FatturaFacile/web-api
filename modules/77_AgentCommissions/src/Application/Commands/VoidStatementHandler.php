<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Commands;

use Modules\AgentCommissions\Models\Statement;
use Modules\AgentCommissions\Services\StatementService;

final class VoidStatementHandler
{
    public function __construct(private readonly StatementService $statements) {}

    public function handle(VoidStatementCommand $command): Statement
    {
        return $this->statements->void(Statement::query()->findOrFail($command->statementId));
    }
}
