<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\GdoTradeTerms\Models\GdoAccrualRun;

final class GetAccrualRunsHandler
{
    /**
     * @return LengthAwarePaginator<int, GdoAccrualRun>
     */
    public function handle(GetAccrualRunsQuery $query): LengthAwarePaginator
    {
        return GdoAccrualRun::query()->when($query->status !== null, fn ($runs) => $runs->where('status', $query->status))->orderByDesc('period_key')->orderByDesc('created_at')->paginate($query->perPage);
    }
}
