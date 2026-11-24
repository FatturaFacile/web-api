<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Queries;

use Modules\AgentCommissions\Models\AgentProfile;
use Modules\AgentCommissions\Services\StatementService;

final class PreviewStatementHandler
{
    public function __construct(private readonly StatementService $statements) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(PreviewStatementQuery $query): array
    {
        return $this->statements->preview($query->tenantId, AgentProfile::query()->findOrFail($query->agentProfileId), $query->from, $query->to);
    }
}
