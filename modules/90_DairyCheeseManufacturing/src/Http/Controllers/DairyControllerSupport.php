<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\DairyCheeseManufacturing\Models\Breeder;

/**
 * Shared helpers of the controllers of the module.
 */
trait DairyControllerSupport
{
    private function view(): void
    {
        Gate::authorize('viewAny', Breeder::class);
    }

    private function manage(): void
    {
        Gate::authorize('manage', Breeder::class);
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }

    private function message(string $key): string
    {
        return __('dairy_cheese.messages.'.$key);
    }

    private function perPage(Request $request): int
    {
        return min(200, max(1, (int) $request->query('per_page', 50)));
    }

    /**
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    private function pageMeta(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }
}
