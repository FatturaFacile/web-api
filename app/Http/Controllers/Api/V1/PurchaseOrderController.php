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
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

#[OA\Tag(name: 'Purchase Orders', description: 'Purchase orders and goods receipt management')]
class PurchaseOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SavePurchaseOrder $save,
        private readonly ReceiveGoods $receiveGoods,
    ) {}

    #[OA\Post(
        path: '/api/v1/purchases/orders',
        summary: 'Create purchase order',
        description: 'Create a new purchase order',
        tags: ['Purchase Orders'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['supplier_id', 'date'],
                properties: [
                    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Purchase order created successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        return $this->successResponse(new CatalogResource($this->save->create($request->validated())), __('purchases.messages.order_created'), Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/purchases/orders/{id}',
        summary: 'Get purchase order',
        description: 'Retrieve purchase order details and items',
        tags: ['Purchase Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Purchase order details retrieved'),
            new OA\Response(response: 404, description: 'Purchase order not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource(PurchaseOrder::with('items')->findOrFail($id)), __('purchases.messages.order_retrieved'));
    }

    #[OA\Put(
        path: '/api/v1/purchases/orders/{id}',
        summary: 'Update purchase order',
        description: 'Update purchase order details and items',
        tags: ['Purchase Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Purchase order updated successfully'),
            new OA\Response(response: 404, description: 'Purchase order not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function update(StorePurchaseOrderRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource($this->save->update(PurchaseOrder::findOrFail($id), $request->validated())), __('purchases.messages.order_updated'));
    }

    #[OA\Post(
        path: '/api/v1/purchases/orders/{id}/confirm',
        summary: 'Confirm purchase order',
        description: 'Confirm a draft purchase order',
        tags: ['Purchase Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Purchase order confirmed'),
            new OA\Response(response: 404, description: 'Purchase order not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function confirm(string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource($this->save->confirm(PurchaseOrder::findOrFail($id))), __('purchases.messages.order_confirmed'));
    }

    /**
     * Receive goods (fully or partially) and load tracked products into stock.
     */
    #[OA\Post(
        path: '/api/v1/purchases/orders/{id}/receive',
        summary: 'Receive goods from purchase order',
        description: 'Receive goods (fully or partially) and register stock receipts',
        tags: ['Purchase Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'notes', type: 'string'),
                    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Goods received successfully'),
            new OA\Response(response: 404, description: 'Purchase order not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function receive(ReceiveGoodsRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $receipt = $this->receiveGoods->execute(PurchaseOrder::findOrFail($id), $validated['lines'] ?? [], collect($validated)->only(['date', 'notes'])->all());

        return $this->successResponse(new CatalogResource($receipt), __('purchases.messages.goods_received'), Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/purchases/goods-receipts',
        summary: 'List goods receipts',
        description: 'Get paginated list of goods receipts',
        tags: ['Purchase Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Goods receipts retrieved successfully', content: new OA\JsonContent(ref: '#/components/schemas/PurchasesGoodsReceiptsListResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function receipts(Request $request): JsonResponse
    {
        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));
        $receipts = GoodsReceipt::query()->latest('date')->paginate($perPage);

        return $this->successResponse(
            data: CatalogResource::collection($receipts),
            message: __('purchases.messages.receipts'),
            meta: ['current_page' => $receipts->currentPage(), 'last_page' => $receipts->lastPage(), 'per_page' => $receipts->perPage(), 'total' => $receipts->total()]
        );
    }

    #[OA\Get(
        path: '/api/v1/purchases/goods-receipts/{id}',
        summary: 'Get goods receipt',
        description: 'Retrieve details and items for a goods receipt',
        tags: ['Purchase Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Goods receipt details retrieved'),
            new OA\Response(response: 404, description: 'Goods receipt not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function showReceipt(string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource(GoodsReceipt::with('items')->findOrFail($id)), __('purchases.messages.receipt'));
    }
}
