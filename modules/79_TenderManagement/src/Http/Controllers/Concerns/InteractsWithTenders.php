<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Modules\TenderManagement\Models\TenderParticipation;

/**
 * User, paging, message and record lookups shared by the tender controllers.
 */
trait InteractsWithTenders
{
    private function userId(): string
    {
        return (string) request()->user()->getKey();
    }

    private function perPage(): int
    {
        return max(1, min(200, (int) request()->query('per_page', 25)));
    }

    private function message(string $key): string
    {
        return __('tender_management.messages.'.$key);
    }

    private function findParticipation(string $id, string $ability): TenderParticipation
    {
        $participation = TenderParticipation::query()->with('notice')->findOrFail($id);
        Gate::authorize($ability, $participation);

        return $participation;
    }

    /**
     * @param  LengthAwarePaginator<int, *>  $page
     * @param  class-string<JsonResource>  $resource
     */
    private function paged(LengthAwarePaginator $page, string $resource, string $message): JsonResponse
    {
        return $this->successResponse($resource::collection($page->items()), $message, 200, [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]);
    }
}
