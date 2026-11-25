<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\IndustrialLaundry\Models\WashBatch;

final class GetWashBatchesHandler
{
    /**
     * @return LengthAwarePaginator<int, WashBatch>
     */
    public function handle(GetWashBatchesQuery $query): LengthAwarePaginator
    {
        return WashBatch::query()
            ->when($query->status !== null, fn ($rows) => $rows->where('rabc_control_status', $query->status))
            ->when($query->from !== null, fn ($rows) => $rows->whereDate('washed_at', '>=', $query->from))
            ->when($query->to !== null, fn ($rows) => $rows->whereDate('washed_at', '<=', $query->to))
            ->orderByDesc('washed_at')
            ->orderByDesc('batch_code')
            ->paginate($query->perPage);
    }
}
