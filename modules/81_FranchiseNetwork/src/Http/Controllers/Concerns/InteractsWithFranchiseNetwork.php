<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Modules\FranchiseNetwork\Models\FranchiseStore;

/**
 * User, paging and message lookup of the request for the franchise network controllers.
 */
trait InteractsWithFranchiseNetwork
{
    private function userId(): string
    {
        return (string) request()->user()->getKey();
    }

    private function perPage(int $default = 25): int
    {
        return max(1, min(100, (int) request()->query('per_page', $default)));
    }

    private function message(string $key): string
    {
        return __('franchise_network.messages.'.$key);
    }

    private function period(): string
    {
        return (string) (request()->query('period') ?: now()->subMonthNoOverflow()->format('Y-m'));
    }

    /**
     * Area managers only reach the stores assigned to them; directors reach every store.
     *
     * @param  Builder<FranchiseStore>  $stores
     * @return Builder<FranchiseStore>
     */
    private function visibleStores($stores)
    {
        return Gate::allows('manage', FranchiseStore::class) ? $stores : $stores->where('area_manager_user_id', $this->userId());
    }

    /**
     * @param  class-string<JsonResource>  $resource
     * @param  LengthAwarePaginator<int, *>  $page
     */
    private function paged(LengthAwarePaginator $page, string $resource): JsonResponse
    {
        return $this->successResponse($resource::collection($page->items()), $this->message('listed'), 200, [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]);
    }
}
