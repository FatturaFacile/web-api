<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\IndustrialLaundry\Models\BillingRun;

final class GetBillingRunsHandler
{
    /**
     * @return LengthAwarePaginator<int, BillingRun>
     */
    public function handle(GetBillingRunsQuery $query): LengthAwarePaginator
    {
        return BillingRun::query()
            ->with(['customer:id,company_name,first_name,last_name', 'contract:id,code'])
            ->when($query->customerId !== null, fn ($rows) => $rows->where('customer_id', $query->customerId))
            ->when($query->status !== null, fn ($rows) => $rows->where('status', $query->status))
            ->orderByDesc('period_start')
            ->paginate($query->perPage);
    }
}
