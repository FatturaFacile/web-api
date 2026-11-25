<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\GdoTradeTerms\Models\GdoContract;

final class GetContractsHandler
{
    /**
     * @return LengthAwarePaginator<int, GdoContract>
     */
    public function handle(GetContractsQuery $query): LengthAwarePaginator
    {
        return $this->list($query);
    }

    /**
     * @return LengthAwarePaginator<int, GdoContract>
     */
    private function list(GetContractsQuery $query): LengthAwarePaginator
    {
        return GdoContract::query()
            ->with('contact:id,company_name,first_name,last_name,country')
            ->when($query->status !== null, fn ($rows) => $rows->where('status', $query->status))
            ->when($query->contactId !== null, fn ($rows) => $rows->where('contact_id', $query->contactId))
            ->when($query->jurisdiction !== null, fn ($rows) => $rows->where('jurisdiction', $query->jurisdiction))
            ->when($query->search !== null && $query->search !== '', function ($rows) use ($query): void {
                $term = '%'.mb_strtolower((string) $query->search).'%';
                $rows->where(fn ($inner) => $inner->whereRaw('LOWER(code) LIKE ?', [$term])->orWhereRaw('LOWER(name) LIKE ?', [$term]));
            })
            ->orderByDesc('starts_on')
            ->orderBy('code')
            ->paginate($query->perPage);
    }
}
