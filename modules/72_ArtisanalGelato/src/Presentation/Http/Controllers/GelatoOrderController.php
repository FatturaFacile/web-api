<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ArtisanalGelato\Application\Services\GelatoGanacheAndOrderService;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoOrder;
use Modules\ArtisanalGelato\Presentation\Http\Controllers\Concerns\MapsGelatoErrors;
use Modules\ArtisanalGelato\Presentation\Http\Requests\GelatoOrderRequest;
use Modules\ArtisanalGelato\Presentation\Http\Resources\GelatoOrderResource;
use OpenApi\Attributes as OA;

/**
 * Assorted praline boxes, custom gelato cakes and take-away tubs: pre-orders and their lifecycle.
 */
final class GelatoOrderController extends Controller
{
    use ApiResponse;
    use MapsGelatoErrors;

    #[OA\Get(path: '/api/v1/gelato/orders', operationId: 'listGelatoOrders', summary: 'Pre-orders', description: 'Optionally filtered by status; ordered by pickup time.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], responses: [new OA\Response(response: 200, description: 'The orders')])]
    public function index(Request $request): JsonResponse
    {
        $query = GelatoOrder::query()->orderBy('pickup_at');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return $this->successResponse(GelatoOrderResource::collection($query->limit(300)->get()));
    }

    #[OA\Post(path: '/api/v1/gelato/orders', operationId: 'createGelatoOrder', summary: 'Create a pre-order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], responses: [new OA\Response(response: 201, description: 'The order'), new OA\Response(response: 422, description: 'Invalid order')])]
    public function store(GelatoOrderRequest $request, GelatoGanacheAndOrderService $service): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(new GelatoOrderResource($service->createOrder($request->validated())), 'Order created', 201));
    }

    #[OA\Patch(path: '/api/v1/gelato/orders/{id}/status', operationId: 'updateGelatoOrderStatus', summary: 'Move an order along its lifecycle', description: 'pre_order, confirmed, ready, collected; any open order can be cancelled.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The order'), new OA\Response(response: 422, description: 'Invalid transition')])]
    public function status(string $id, GelatoOrderRequest $request, GelatoGanacheAndOrderService $service): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(new GelatoOrderResource($service->updateOrderStatus($id, (string) $request->validated('status'))), 'Order updated'));
    }
}
