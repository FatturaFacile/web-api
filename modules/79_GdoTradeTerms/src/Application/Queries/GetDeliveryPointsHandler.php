<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\GdoTradeTerms\Models\GdoDeliveryPoint;

final class GetDeliveryPointsHandler
{
    /**
     * @return LengthAwarePaginator<int, GdoDeliveryPoint>
     */
    public function handle(GetDeliveryPointsQuery $query): LengthAwarePaginator
    {
        return GdoDeliveryPoint::query()->when($query->contactId !== null, fn ($rows) => $rows->where('contact_id', $query->contactId))->when($query->search !== null && $query->search !== '', fn ($rows) => $rows->where(fn ($inner) => $inner->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower((string) $query->search).'%'])->orWhereRaw('LOWER(code) LIKE ?', ['%'.mb_strtolower((string) $query->search).'%'])))->orderBy('code')->paginate($query->perPage);
    }
}
