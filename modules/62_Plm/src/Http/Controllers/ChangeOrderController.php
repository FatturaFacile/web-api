<?php

declare(strict_types=1);

namespace Modules\Plm\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Plm\Http\Controllers\Concerns\InteractsWithPlm;
use Modules\Plm\Http\Requests\ChangeOrderRequest;
use Modules\Plm\Http\Requests\DecisionRequest;
use Modules\Plm\Http\Resources\ChangeOrderResource;
use Modules\Plm\Models\PlmChangeOrder;
use Modules\Plm\Services\ChangeOrderService;
use OpenApi\Attributes as OA;

/**
 * Engineering change orders: approval flow, implementation and the links to items and bills of material.
 */
final class ChangeOrderController extends Controller
{
    use InteractsWithPlm;

    public function __construct(private readonly ChangeOrderService $orders) {}

    #[OA\Get(path: '/api/v1/plm/change-orders', operationId: 'listPlmChangeOrders', description: 'Change orders, the latest first, with approvers and affected items and bills. Filters: status, priority, change_type, search (code or title), awaiting_me (boolean: orders in review where it is the turn of the user to decide).', summary: 'List change orders', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], responses: [
        new OA\Response(response: 200, description: 'Change orders', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PlmChangeOrder'))])),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlmForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PlmChangeOrder::class);
        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', PlmChangeOrder::Statuses)],
            'priority' => ['nullable', 'in:'.implode(',', PlmChangeOrder::Priorities)],
            'change_type' => ['nullable', 'in:'.implode(',', PlmChangeOrder::ChangeTypes)],
            'search' => ['nullable', 'string', 'max:100'],
            'awaiting_me' => ['nullable', 'boolean'],
        ]);

        $userId = $this->userId();

        return ChangeOrderResource::collection(
            PlmChangeOrder::query()
                ->with(['approvals.approver:id,name', 'affectedItems.item:id,item_code,name', 'affectedBoms.bom:id,bom_number,name,revision,status'])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['priority']), static fn ($query) => $query->where('priority', $filters['priority']))
                ->when(isset($filters['change_type']), static fn ($query) => $query->where('change_type', $filters['change_type']))
                ->when(isset($filters['search']), static fn ($query) => $query->where(static fn ($inner) => $inner->where('code', 'like', '%'.$filters['search'].'%')->orWhere('title', 'like', '%'.$filters['search'].'%')))
                ->when((bool) ($filters['awaiting_me'] ?? false), static fn ($query) => $query->where('status', PlmChangeOrder::InReview)->whereHas('approvals', static fn ($approvals) => $approvals
                    ->where('approver_id', $userId)
                    ->where('status', 'pending')
                    ->whereNotExists(static fn ($earlier) => $earlier
                        ->from('plm_change_approvals as earlier')
                        ->whereColumn('earlier.change_order_id', 'plm_change_approvals.change_order_id')
                        ->where('earlier.status', 'pending')
                        ->whereColumn('earlier.sequence', '<', 'plm_change_approvals.sequence'))))
                ->orderByDesc('created_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/plm/change-orders', operationId: 'storePlmChangeOrder', description: 'Create a draft change order. approvers are the users of the company in the order they decide; affected_items give the revision each item gets on implementation; affected_boms link bills of material of manufacturing.', summary: 'Create a change order', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlmChangeOrderRequest')), tags: ['PLM'], responses: [
        new OA\Response(response: 201, description: 'Change order created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlmChangeOrder')])),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlmForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlmUnprocessable', response: 422),
    ])]
    public function store(ChangeOrderRequest $request): JsonResponse
    {
        $this->authorize('create', PlmChangeOrder::class);

        return (new ChangeOrderResource($this->orders->create($this->tenantId(), $this->userId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/plm/change-orders/{id}', operationId: 'showPlmChangeOrder', description: 'Return a change order with its approval flow, affected items and affected bills of material.', summary: 'Show a change order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 200, description: 'Change order', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlmChangeOrder')])),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlmForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlmNotFound', response: 404),
    ])]
    public function show(string $id): ChangeOrderResource
    {
        $order = PlmChangeOrder::query()->findOrFail($id);
        $this->authorize('view', $order);

        return new ChangeOrderResource($this->orders->loaded($order));
    }

    #[OA\Put(path: '/api/v1/plm/change-orders/{id}', operationId: 'updatePlmChangeOrder', description: 'Change a draft order; approvers, affected items and affected bills are replaced as a whole when sent. Any other status is refused with 422.', summary: 'Update a change order', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlmChangeOrderRequest')), tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 200, description: 'Change order updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlmChangeOrder')])),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlmForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlmNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlmUnprocessable', response: 422),
    ])]
    public function update(ChangeOrderRequest $request, string $id): ChangeOrderResource
    {
        $order = PlmChangeOrder::query()->findOrFail($id);
        $this->authorize('update', $order);

        return new ChangeOrderResource($this->orders->update($order, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/plm/change-orders/{id}', operationId: 'deletePlmChangeOrder', description: 'Delete a draft change order (soft delete).', summary: 'Delete a change order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 204, description: 'Change order deleted'),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlmForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlmNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlmUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $order = PlmChangeOrder::query()->findOrFail($id);
        $this->authorize('delete', $order);
        $this->orders->delete($order);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/plm/change-orders/{id}/submit', operationId: 'submitPlmChangeOrder', description: 'Send a draft for approval: it needs at least one approver and at least one affected item or bill of material. The approvers then decide in sequence.', summary: 'Submit a change order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 200, description: 'Change order in review', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlmChangeOrder')])),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlmForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlmNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlmUnprocessable', response: 422),
    ])]
    public function submit(string $id): ChangeOrderResource
    {
        $order = PlmChangeOrder::query()->findOrFail($id);
        $this->authorize('update', $order);

        return new ChangeOrderResource($this->orders->submit($order));
    }

    #[OA\Post(path: '/api/v1/plm/change-orders/{id}/decide', operationId: 'decidePlmChangeOrder', description: 'The approver whose turn it is approves or rejects. Approving moves the flow to the next approver and the last approval approves the order; a rejection (comment required) rejects it at once. Anyone else gets 422.', summary: 'Decide a change order', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlmDecisionRequest')), tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 200, description: 'Decision recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlmChangeOrder')])),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlmForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlmNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlmUnprocessable', response: 422),
    ])]
    public function decide(DecisionRequest $request, string $id): ChangeOrderResource
    {
        $order = PlmChangeOrder::query()->findOrFail($id);
        $this->authorize('view', $order);

        return new ChangeOrderResource($this->orders->decide($order, (string) $this->userId(), (string) $request->validated('decision'), $request->validated('comment')));
    }

    #[OA\Post(path: '/api/v1/plm/change-orders/{id}/cancel', operationId: 'cancelPlmChangeOrder', description: 'Cancel a draft, in review or approved order.', summary: 'Cancel a change order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 200, description: 'Change order cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlmChangeOrder')])),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlmForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlmNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlmUnprocessable', response: 422),
    ])]
    public function cancel(string $id): ChangeOrderResource
    {
        $order = PlmChangeOrder::query()->findOrFail($id);
        $this->authorize('update', $order);

        return new ChangeOrderResource($this->orders->cancel($order));
    }

    #[OA\Post(path: '/api/v1/plm/change-orders/{id}/implement', operationId: 'implementPlmChangeOrder', description: 'Implement an approved order: every affected item gets its new revision released (effective from the effective date, default today) and its previously released revision becomes obsolete.', summary: 'Implement a change order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 200, description: 'Change order implemented', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlmChangeOrder')])),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlmForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlmNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlmUnprocessable', response: 422),
    ])]
    public function implement(string $id): ChangeOrderResource
    {
        $order = PlmChangeOrder::query()->findOrFail($id);
        $this->authorize('update', $order);

        return new ChangeOrderResource($this->orders->implement($order));
    }
}
