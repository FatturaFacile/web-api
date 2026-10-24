<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Purchases\ReceiveGoods;
use App\Actions\Purchases\SavePurchaseOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\ReceiveGoodsRequest;
use App\Http\Requests\Purchases\StorePurchaseOrderRequest;
use App\Http\Resources\CatalogResource;
use App\Http\Responses\ApiResponse;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PurchaseOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SavePurchaseOrder $save,
        private readonly ReceiveGoods $receiveGoods,
    ) {}

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        return $this->successResponse(new CatalogResource($this->save->create($request->validated())), __('purchases.messages.order_created'), Response::HTTP_CREATED);
    }

    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource(PurchaseOrder::with('items')->findOrFail($id)), __('purchases.messages.order_retrieved'));
    }

    public function update(StorePurchaseOrderRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource($this->save->update(PurchaseOrder::findOrFail($id), $request->validated())), __('purchases.messages.order_updated'));
    }

    public function confirm(string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource($this->save->confirm(PurchaseOrder::findOrFail($id))), __('purchases.messages.order_confirmed'));
    }

    /**
     * Receive goods (fully or partially) and load tracked products into stock.
     */
    public function receive(ReceiveGoodsRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $receipt = $this->receiveGoods->execute(PurchaseOrder::findOrFail($id), $validated['lines'] ?? [], collect($validated)->only(['date', 'notes'])->all());

        return $this->successResponse(new CatalogResource($receipt), __('purchases.messages.goods_received'), Response::HTTP_CREATED);
    }

    public function receipts(Request $request): JsonResponse
    {
        $receipts = GoodsReceipt::query()->latest('date')->paginate(min(100, max(5, (int) $request->query('per_page', 15))));

        return $this->successResponse(
            data: CatalogResource::collection($receipts),
            message: __('purchases.messages.receipts'),
            meta: ['current_page' => $receipts->currentPage(), 'last_page' => $receipts->lastPage(), 'per_page' => $receipts->perPage(), 'total' => $receipts->total()]
        );
    }

    public function showReceipt(string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource(GoodsReceipt::with('items')->findOrFail($id)), __('purchases.messages.receipt'));
    }
}
