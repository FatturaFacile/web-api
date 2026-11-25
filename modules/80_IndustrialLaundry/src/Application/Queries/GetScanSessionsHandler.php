<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\IndustrialLaundry\Models\ScanSession;

final class GetScanSessionsHandler
{
    /**
     * @return LengthAwarePaginator<int, ScanSession>
     */
    public function handle(GetScanSessionsQuery $query): LengthAwarePaginator
    {
        return ScanSession::query()
            ->with('customer:id,company_name,first_name,last_name')
            ->when($query->direction !== null, fn ($rows) => $rows->where('direction', $query->direction))
            ->when($query->contactId !== null, fn ($rows) => $rows->where('contact_id', $query->contactId))
            ->when($query->from !== null, fn ($rows) => $rows->whereDate('scanned_at', '>=', $query->from))
            ->when($query->to !== null, fn ($rows) => $rows->whereDate('scanned_at', '<=', $query->to))
            ->when($query->rollCageBarcode !== null && $query->rollCageBarcode !== '', fn ($rows) => $rows->where('roll_cage_barcode', $query->rollCageBarcode))
            ->orderByDesc('scanned_at')
            ->paginate($query->perPage);
    }
}
