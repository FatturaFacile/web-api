<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductBrandRequest;
use App\Models\ProductBrand;
use Illuminate\Http\JsonResponse;

class ProductBrandController extends Controller
{
    use HandlesLookupCrud;

    protected function modelClass(): string
    {
        return ProductBrand::class;
    }

    protected function label(): string
    {
        return __('api.labels.product_brand');
    }

    public function store(StoreProductBrandRequest $request): JsonResponse
    {
        return $this->createRecord($request->validated());
    }

    public function update(StoreProductBrandRequest $request, string $id): JsonResponse
    {
        return $this->updateRecord($id, $request->validated());
    }
}
