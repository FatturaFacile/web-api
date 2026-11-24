<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Paginated list responses of the Projects API: data plus pagination meta, with the page size bounded to 100 so a
 * client cannot ask for an unbounded result.
 */
trait PaginatesProjectRecords
{
    protected function perPage(Request $request, int $default = 25): int
    {
        return max(1, min(100, (int) $request->query('per_page', (string) $default)));
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @param  class-string<JsonResource>  $resource
     */
    protected function paginated(LengthAwarePaginator $page, string $resource): JsonResponse
    {
        return response()->json([
            'data' => $resource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
