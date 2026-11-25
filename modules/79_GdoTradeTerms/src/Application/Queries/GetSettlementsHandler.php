<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\GdoTradeTerms\Models\GdoSettlement;

final class GetSettlementsHandler
{
    /**
     * @return LengthAwarePaginator<int, GdoSettlement>
     */
    public function handle(GetSettlementsQuery $query): LengthAwarePaginator
    {
        return GdoSettlement::query()->with('contract:id,code,name,currency')->when($query->status !== null, fn ($rows) => $rows->where('status', $query->status))->when($query->contractId !== null, fn ($rows) => $rows->where('contract_id', $query->contractId))->orderByDesc('period_end')->orderByDesc('code')->paginate($query->perPage);
    }
}
