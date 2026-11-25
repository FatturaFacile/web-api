<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\IndustrialLaundry\Models\ParContract;

final class GetParContractsHandler
{
    /**
     * @return LengthAwarePaginator<int, ParContract>
     */
    public function handle(GetParContractsQuery $query): LengthAwarePaginator
    {
        return ParContract::query()
            ->with(['customer:id,company_name,first_name,last_name,country', 'lines'])
            ->when($query->customerId !== null, fn ($rows) => $rows->where('customer_id', $query->customerId))
            ->when($query->segment !== null, fn ($rows) => $rows->where('customer_segment', $query->segment))
            ->when($query->active !== null, fn ($rows) => $rows->where('is_active', $query->active))
            ->when($query->search !== null && $query->search !== '', fn ($rows) => $rows->whereRaw('LOWER(code) LIKE ?', ['%'.mb_strtolower((string) $query->search).'%']))
            ->orderByDesc('valid_from')
            ->orderBy('code')
            ->paginate($query->perPage);
    }
}
