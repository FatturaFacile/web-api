<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Application\Handlers;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\MrpPlanning\Application\Queries\ListMrpRunsQuery;
use Modules\MrpPlanning\Infrastructure\Models\MrpRunExecution;

final class ListMrpRunsHandler
{
    /**
     * @return LengthAwarePaginator<int, MrpRunExecution>
     */
    public function handle(ListMrpRunsQuery $query): LengthAwarePaginator
    {
        return MrpRunExecution::query()->where('tenant_id', $query->tenantId)
            ->when($query->status !== null, static fn ($builder) => $builder->where('status', $query->status))
            ->orderByDesc('started_at')->orderByDesc('id')
            ->paginate(perPage: max(1, min(100, $query->perPage)), page: max(1, $query->page));
    }
}
