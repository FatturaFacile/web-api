<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\AgentCommissions\Models\Statement;

final class ListStatementsHandler
{
    public function handle(ListStatementsQuery $query): LengthAwarePaginator
    {
        return Statement::query()
            ->with('agent.representative:id,name,email')
            ->when($query->agentProfileId !== null, static fn (Builder $builder) => $builder->where('agent_profile_id', $query->agentProfileId))
            ->when($query->status !== null, static fn (Builder $builder) => $builder->where('status', $query->status))
            ->when($query->year !== null, static fn (Builder $builder) => $builder->whereYear('period_end', $query->year))
            ->orderByDesc('period_end')
            ->orderByDesc('number')
            ->paginate($query->perPage);
    }
}
