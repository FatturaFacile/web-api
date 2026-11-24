<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Commands;

use Modules\AgentCommissions\Domain\Enums\PeriodType;
use Modules\AgentCommissions\Events\StatementGeneratedEvent;
use Modules\AgentCommissions\Models\AgentProfile;
use Modules\AgentCommissions\Models\Statement;
use Modules\AgentCommissions\Services\StatementService;

final class GenerateStatementHandler
{
    public function __construct(private readonly StatementService $statements) {}

    public function handle(GenerateStatementCommand $command): Statement
    {
        $profile = AgentProfile::query()->findOrFail($command->agentProfileId);
        $statement = $this->statements->generate($command->tenantId, $profile, $command->from, $command->to, PeriodType::from($command->periodType), $command->actorId);
        event(new StatementGeneratedEvent((string) $statement->id, (string) $statement->agent_profile_id, $command->tenantId));

        return $statement;
    }
}
