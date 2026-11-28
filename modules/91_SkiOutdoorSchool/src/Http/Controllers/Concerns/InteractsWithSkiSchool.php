<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * User, paging and message lookup of the request for the ski school controllers.
 */
trait InteractsWithSkiSchool
{
    private function userId(): ?string
    {
        $id = request()->user()?->getKey();

        return $id === null ? null : (string) $id;
    }

    private function perPage(int $default = 25): int
    {
        return max(1, min(100, (int) request()->query('per_page', $default)));
    }

    private function message(string $key): string
    {
        return __('ski_school.messages.'.$key);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return request()->query();
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
