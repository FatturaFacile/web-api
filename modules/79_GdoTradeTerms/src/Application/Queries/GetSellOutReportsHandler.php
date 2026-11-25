<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\GdoTradeTerms\Models\GdoSellOutReport;

final class GetSellOutReportsHandler
{
    /**
     * @return LengthAwarePaginator<int, GdoSellOutReport>
     */
    public function handle(GetSellOutReportsQuery $query): LengthAwarePaginator
    {
        return GdoSellOutReport::query()->with(['deliveryPoint:id,code,name'])->when($query->contractId !== null, fn ($rows) => $rows->where('contract_id', $query->contractId))->when($query->deliveryPointId !== null, fn ($rows) => $rows->where('delivery_point_id', $query->deliveryPointId))->when($query->from !== null, fn ($rows) => $rows->whereDate('period_end', '>=', $query->from))->when($query->to !== null, fn ($rows) => $rows->whereDate('period_start', '<=', $query->to))->orderByDesc('period_start')->paginate($query->perPage);
    }
}
