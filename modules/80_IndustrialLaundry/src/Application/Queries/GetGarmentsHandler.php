<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\IndustrialLaundry\Models\Garment;

final class GetGarmentsHandler
{
    /**
     * @return LengthAwarePaginator<int, Garment>
     */
    public function handle(GetGarmentsQuery $query): LengthAwarePaginator
    {
        return Garment::query()
            ->with('customer:id,company_name,first_name,last_name')
            ->when($query->state !== null, fn ($rows) => $rows->where('current_lifecycle_state', $query->state))
            ->when($query->articleType !== null, fn ($rows) => $rows->where('article_type', $query->articleType))
            ->when($query->customerId !== null, fn ($rows) => $rows->where('assigned_customer_id', $query->customerId))
            ->when($query->search !== null && $query->search !== '', function ($rows) use ($query): void {
                $term = '%'.mb_strtolower((string) $query->search).'%';
                $rows->where(fn ($inner) => $inner->whereRaw('LOWER(epc_uid_rfid_hex) LIKE ?', [$term])->orWhereRaw('LOWER(assigned_wearer_employee_name) LIKE ?', [$term])->orWhereRaw('LOWER(assigned_locker_code) LIKE ?', [$term])->orWhereRaw('LOWER(thermal_barcode) LIKE ?', [$term]));
            })
            ->orderBy('epc_uid_rfid_hex')
            ->paginate($query->perPage);
    }
}
