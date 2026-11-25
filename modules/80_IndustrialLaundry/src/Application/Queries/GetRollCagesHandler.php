<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\IndustrialLaundry\Models\RollCage;

final class GetRollCagesHandler
{
    /**
     * @return LengthAwarePaginator<int, RollCage>
     */
    public function handle(GetRollCagesQuery $query): LengthAwarePaginator
    {
        return RollCage::query()
            ->with('holder:id,company_name,first_name,last_name')
            ->when($query->barcode !== null && $query->barcode !== '', fn ($rows) => $rows->where('barcode', $query->barcode))
            ->when($query->search !== null && $query->search !== '', function ($rows) use ($query): void {
                $term = '%'.mb_strtolower((string) $query->search).'%';
                $rows->where(fn ($inner) => $inner->whereRaw('LOWER(barcode) LIKE ?', [$term])->orWhereRaw('LOWER(label) LIKE ?', [$term]));
            })
            ->orderBy('barcode')
            ->paginate($query->perPage);
    }
}
