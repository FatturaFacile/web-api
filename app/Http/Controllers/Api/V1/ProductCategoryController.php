<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductCategoryRequest;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;

class ProductCategoryController extends Controller
{
    use HandlesLookupCrud;

    protected function modelClass(): string
    {
        return ProductCategory::class;
    }

    protected function label(): string
    {
        return __('api.labels.product_category');
    }

    public function store(StoreProductCategoryRequest $request): JsonResponse
    {
        return $this->createRecord($request->validated());
    }

    public function update(StoreProductCategoryRequest $request, string $id): JsonResponse
    {
        return $this->updateRecord($id, $request->validated());
    }
}
