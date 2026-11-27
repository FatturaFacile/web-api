<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Subcontracting\Http\Controllers\Concerns\InteractsWithSubcontracting;
use Modules\Subcontracting\Http\Requests\CreateSubcontractingOrderRequest;
use Modules\Subcontracting\Http\Requests\OrderActionRequest;
use Modules\Subcontracting\Http\Requests\OrderIndexRequest;
use Modules\Subcontracting\Http\Resources\SubcontractingOrderResource;
use Modules\Subcontracting\Models\SubcontractingOrder;
use Modules\Subcontracting\Services\SubcontractingOrderService;
use OpenApi\Attributes as OA;

/**
 * Subcontracting orders (conto lavoro passivo and attivo): list, create from BOM or routing step, confirm, cancel.
 */
final class SubcontractingOrderController extends Controller
{
    use ApiResponse;
    use InteractsWithSubcontracting;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly SubcontractingOrderService $orders) {}

    #[OA\Get(path: '/api/v1/subcontracting/orders', operationId: 'listSubcontractingOrders', summary: 'Paginated subcontracting orders', security: self::SECURITY, tags: ['Subcontracting'], responses: [new OA\Response(response: 200, description: 'Orders')])]
    public function index(OrderIndexRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SubcontractingOrder::class);
        $filters = $request->validated();
        $search = isset($filters['search']) ? mb_strtolower((string) $filters['search']) : null;

        $page = SubcontractingOrder::query()
            ->where('tenant_id', $this->tenantId())
            ->when($filters['direction'] ?? null, static fn ($q, string $v) => $q->where('direction', $v))
            ->when($filters['status'] ?? null, static fn ($q, string $v) => $q->where('status', $v))
            ->when($filters['partner_id'] ?? null, static fn ($q, string $v) => $q->where('partner_id', $v))
            ->when($search, static fn ($q, string $v) => $q->where(static fn ($w) => $w->whereRaw('LOWER(order_number) LIKE ?', ['%'.$v.'%'])->orWhereRaw('LOWER(partner_name) LIKE ?', ['%'.$v.'%'])))
            ->latest('created_at')
            ->paginate($this->perPage(), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return $this->paged($page, SubcontractingOrderResource::class);
    }

    #[OA\Post(path: '/api/v1/subcontracting/orders', operationId: 'createSubcontractingOrder', summary: 'Create a subcontracting order from a BOM, a routing step or explicit lines', security: self::SECURITY, tags: ['Subcontracting'], responses: [new OA\Response(response: 201, description: 'The order'), new OA\Response(response: 422, description: 'Rule refused')])]
    public function store(CreateSubcontractingOrderRequest $request): JsonResponse
    {
        $order = $this->orders->create($request->validated(), $this->tenantId(), $this->userId());

        return $this->createdResponse(new SubcontractingOrderResource($order), $this->message('saved'));
    }

    #[OA\Get(path: '/api/v1/subcontracting/orders/{id}', operationId: 'showSubcontractingOrder', summary: 'One subcontracting order with its component lines', security: self::SECURITY, tags: ['Subcontracting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The order'), new OA\Response(response: 404, description: 'Not found')])]
    public function show(string $id): JsonResponse
    {
        $order = $this->ownedOrder($id);
        Gate::authorize('view', $order);

        return $this->successResponse(new SubcontractingOrderResource($order->load('components')), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/subcontracting/orders/{id}/confirm', operationId: 'confirmSubcontractingOrder', summary: 'Confirm a draft order', security: self::SECURITY, tags: ['Subcontracting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The order')])]
    public function confirm(OrderActionRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new SubcontractingOrderResource($this->orders->confirm($this->ownedOrder($id))), $this->message('confirmed'));
    }

    #[OA\Post(path: '/api/v1/subcontracting/orders/{id}/cancel', operationId: 'cancelSubcontractingOrder', summary: 'Cancel an order that has not moved goods', security: self::SECURITY, tags: ['Subcontracting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The order')])]
    public function cancel(OrderActionRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new SubcontractingOrderResource($this->orders->cancel($this->ownedOrder($id))), $this->message('cancelled'));
    }
}
