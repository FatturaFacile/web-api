<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers\Recruiting;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Pagination envelope of the recruiting API: `{ data: [...], meta: { current_page, last_page, per_page, total } }`.
 */
trait RespondsWithRecruitingPages
{
    protected function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }

    /**
     * Filter on a UUID column; a malformed id matches nothing instead of failing on the uuid column type.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    protected function whereUuidFilter(Builder $query, string $column, mixed $value): void
    {
        if (is_string($value) && Str::isUuid($value)) {
            $query->where($column, $value);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    protected function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->query('per_page', '25')));
    }

    /**
     * @template TItem
     *
     * @param  LengthAwarePaginator<int, TItem>  $paginator
     * @param  callable(TItem): array<string, mixed>  $map
     */
    protected function paginatedResponse(LengthAwarePaginator $paginator, callable $map): JsonResponse
    {
        return response()->json([
            'data' => array_values(array_map($map, $paginator->items())),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
