<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Controllers\Concerns;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Modules\ProductCatalog\Policies\CatalogPolicy;

/**
 * Access gate, record lookup and response helpers of the catalog controllers.
 */
trait InteractsWithCatalog
{
    private function authorizeCatalog(): void
    {
        Gate::authorize(CatalogPolicy::ABILITY);
    }

    private function message(string $key): string
    {
        return __('product_catalog.messages.'.$key);
    }

    /**
     * The product of the active company (a product of another company is a 404).
     */
    private function product(string $id): Product
    {
        return Product::query()->findOrFail($id);
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
