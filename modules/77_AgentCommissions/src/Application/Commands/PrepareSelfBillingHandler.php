<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Commands;

use Modules\AgentCommissions\Models\Statement;
use Modules\AgentCommissions\Services\StatementService;

final class PrepareSelfBillingHandler
{
    public function __construct(private readonly StatementService $statements) {}

    public function handle(PrepareSelfBillingCommand $command): Statement
    {
        return $this->statements->prepareSelfBilling(Statement::query()->findOrFail($command->statementId), $command->date);
    }
}
