<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreProductRequest;
use App\Http\Requests\Inventory\UpdateProductRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class InventoryController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function products(Request $request): JsonResponse
    {
        $products = Product::latest()->paginate(25);

        return $this->successResponse(data: $products, message: 'Products retrieved');
    }

    public function storeProduct(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());

        return $this->successResponse(
            data: $product,
            message: 'Product created successfully',
            statusCode: Response::HTTP_CREATED
        );
    }

    public function updateProduct(UpdateProductRequest $request, string $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $product->update($request->validated());

        return $this->successResponse(data: $product->refresh(), message: __('inventory.messages.product_updated'));
    }

    public function warehouses(): JsonResponse
    {
        $warehouses = DB::table('warehouses')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->whereNull('deleted_at')
            ->get();

        return $this->successResponse(data: $warehouses, message: 'Warehouses retrieved');
    }

    public function movements(Request $request): JsonResponse
    {
        $movements = DB::table('stock_movements')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->latest()
            ->paginate(25);

        return $this->successResponse(data: $movements, message: 'Stock movements retrieved');
    }

    public function deliveryNotes(Request $request): JsonResponse
    {
        $ddts = DB::table('delivery_notes')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->whereNull('deleted_at')
            ->latest('date')
            ->paginate(25);

        return $this->successResponse(data: $ddts, message: 'Delivery notes (DDT) retrieved');
    }
}
