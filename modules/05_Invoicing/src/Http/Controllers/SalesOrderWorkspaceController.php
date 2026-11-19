<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Enums\SalesOrderStatus;
use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Http\Requests\AtpCheckRequest;
use Modules\Invoicing\Http\Requests\CancelWorkspaceOrderRequest;
use Modules\Invoicing\Http\Requests\ConfirmWorkspaceOrderRequest;
use Modules\Invoicing\Http\Requests\CreateDdtRequest;
use Modules\Invoicing\Http\Requests\CreditReleaseRequest;
use Modules\Invoicing\Http\Requests\WorkspaceSalesOrderRequest;
use Modules\Invoicing\Http\Resources\SalesDeliveryNoteResource;
use Modules\Invoicing\Http\Resources\WorkspaceSalesOrderResource;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderItem;
use Modules\Invoicing\Services\AtpCtpCalculationService;
use Modules\Invoicing\Services\CustomerCreditControlService;
use Modules\Invoicing\Services\ReplenishmentTriggerService;
use Modules\Invoicing\Services\SalesOrderFulfillmentService;
use Modules\Invoicing\Services\SalesOrderService;
use OpenApi\Attributes as OA;

/**
 * The sales orders workspace API (`/api/v1/sales-orders`): draft CRUD, real-time ATP/CTP check, confirmation behind the
 * credit check with stock allocation, credit release, replenishment pegging, partial shipments with backorders,
 * cancellation and the backorder monitor.
 */
final class SalesOrderWorkspaceController extends Controller
{
    public function __construct(
        private readonly SalesOrderServiceInterface $orders,
        private readonly SalesOrderService $drafts,
        private readonly SalesOrderFulfillmentService $fulfillment,
        private readonly CustomerCreditControlService $credit,
        private readonly ReplenishmentTriggerService $replenishment,
        private readonly AtpCtpCalculationService $atp,
        private readonly TenantContext $tenantContext,
    ) {}

    #[OA\Get(
        path: '/api/v1/sales-orders',
        operationId: 'listWorkspaceSalesOrders',
        description: 'Paginated sales orders filterable by status, order type, customer, credit block, backorders and date range.',
        summary: 'List sales orders',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Orders Workspace'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'order_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customer_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'credit_blocked', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'has_backorders', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object'))]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = SalesOrder::query()->with(['customer', 'blanketAgreement', 'items'])
            ->when($request->query('status'), static fn ($q, $value) => $q->where('status', (string) $value))
            ->when($request->query('order_type'), static fn ($q, $value) => $q->where('order_type', (string) $value))
            ->when($request->query('customer_id'), static fn ($q, $value) => $q->where('contact_id', (string) $value))
            ->when($request->boolean('credit_blocked'), static fn ($q) => $q->where('status', SalesOrderStatus::OnHold->value)->where('credit_status', 'on_hold'))
            ->when($request->boolean('has_backorders'), static fn ($q) => $q->whereHas('items', static fn ($items) => $items->where('is_backorder', true)))
            ->when($request->query('from'), static fn ($q, $value) => $q->whereDate('date', '>=', (string) $value))
            ->when($request->query('to'), static fn ($q, $value) => $q->whereDate('date', '<=', (string) $value))
            ->orderByDesc('date')->orderByDesc('order_number');

        return WorkspaceSalesOrderResource::collection($query->paginate(min(100, max(1, (int) $request->query('per_page', 25)))));
    }

    #[OA\Post(
        path: '/api/v1/sales-orders',
        operationId: 'createWorkspaceSalesOrder',
        description: 'Creates a draft sales order, priced from the price lists when a line has no price.',
        summary: 'Create sales order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Orders Workspace'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 201, description: 'Sales order created', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(WorkspaceSalesOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $items = $validated['items'];
        unset($validated['items']);

        $order = $this->orders->createOrder($validated + ['tenant_id' => (string) $this->tenantContext->getTenantId(), 'created_by' => $this->userId($request)], $items);

        return (new WorkspaceSalesOrderResource($order->load(['customer', 'items'])))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/sales-orders/{id}',
        operationId: 'showWorkspaceSalesOrder',
        description: 'A sales order with lines, backorder tree, delivery notes, replenishment pegging, credit exposure and audit trail.',
        summary: 'Get sales order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Orders Workspace'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): WorkspaceSalesOrderResource
    {
        return new WorkspaceSalesOrderResource($this->detail($id));
    }

    #[OA\Put(
        path: '/api/v1/sales-orders/{id}',
        operationId: 'updateWorkspaceSalesOrder',
        description: 'Rewrites a draft sales order.',
        summary: 'Update sales order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Orders Workspace'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Sales order updated', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 422, description: 'Only a draft can be edited', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(WorkspaceSalesOrderRequest $request, string $id): WorkspaceSalesOrderResource
    {
        $validated = $request->validated();
        $items = $validated['items'];
        unset($validated['items']);

        $this->drafts->replaceDraft(SalesOrder::query()->findOrFail($id), $validated, $items);

        return new WorkspaceSalesOrderResource($this->detail($id));
    }

    #[OA\Delete(
        path: '/api/v1/sales-orders/{id}',
        operationId: 'deleteWorkspaceSalesOrder',
        description: 'Deletes a draft sales order.',
        summary: 'Delete sales order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Orders Workspace'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Sales order deleted'),
            new OA\Response(response: 422, description: 'Only a draft can be deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $order = SalesOrder::query()->findOrFail($id);

        if ($order->status !== SalesOrderStatus::Draft->value) {
            return response()->json(['message' => __('sales.order_not_draft'), 'errors' => ['status' => [__('sales.order_not_draft')]]], 422);
        }

        $order->delete();

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/sales-orders/atp-check',
        operationId: 'atpCheckWorkspaceSalesOrder',
        description: 'Real-time ATP/CTP of candidate lines before the order is saved: quantity available now, shortage, earliest ATP date, CTP date and the fulfilment suggestion.',
        summary: 'ATP/CTP check of order lines',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Orders Workspace'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object'))]
    )]
    public function atpCheck(AtpCheckRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $tenantId = (string) $this->tenantContext->getTenantId();

        $results = array_map(fn (array $line): array => $this->atp->check(
            $tenantId,
            (string) $line['product_id'],
            $line['quantity'],
            $line['warehouse_id'] ?? $validated['warehouse_id'] ?? null,
            $line['requested_date'] ?? null,
        ), $validated['lines']);

        return response()->json(['data' => $results]);
    }

    #[OA\Post(
        path: '/api/v1/sales-orders/{id}/confirm',
        operationId: 'confirmWorkspaceSalesOrder',
        description: 'Runs the credit check; a passing order is confirmed, its ATP annotated, stock allocated and, unless disabled, its shortages pegged to replenishment. A failing order is blocked for credit approval.',
        summary: 'Confirm sales order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Orders Workspace'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Order confirmed or blocked for credit', content: new OA\JsonContent(type: 'object'))]
    )]
    public function confirm(ConfirmWorkspaceOrderRequest $request, string $id): WorkspaceSalesOrderResource
    {
        $this->fulfillment->confirm(SalesOrder::query()->findOrFail($id), $this->userId($request) ?? 'system', $request->boolean('auto_replenish', true));

        return new WorkspaceSalesOrderResource($this->detail($id));
    }

    #[OA\Post(
        path: '/api/v1/sales-orders/{id}/credit-release',
        operationId: 'creditReleaseWorkspaceSalesOrder',
        description: 'Credit manager override of an order blocked for credit (permission sales_orders.approve_credit): records the approver and the justification, confirms the order and allocates its stock.',
        summary: 'Release credit block',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Orders Workspace'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Credit block released', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 403, description: 'Missing permission sales_orders.approve_credit', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'The order is not blocked by credit', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function creditRelease(CreditReleaseRequest $request, string $id): WorkspaceSalesOrderResource
    {
        $order = SalesOrder::query()->findOrFail($id);
        $this->authorize('approveCredit', $order);
        $userId = $this->userId($request) ?? 'system';

        $released = $this->credit->release($order, $userId, (string) $request->validated('notes'));
        $this->fulfillment->allocate($released, $userId, true);

        return new WorkspaceSalesOrderResource($this->detail($id));
    }

    #[OA\Post(
        path: '/api/v1/sales-orders/{id}/generate-replenishment',
        operationId: 'generateReplenishmentWorkspaceSalesOrder',
        description: 'Pegs the open shortages of the order to purchase requisition or production order requests (once per line).',
        summary: 'Generate replenishment',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Orders Workspace'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Replenishment generated', content: new OA\JsonContent(type: 'object'))]
    )]
    public function generateReplenishment(Request $request, string $id): WorkspaceSalesOrderResource
    {
        $this->replenishment->generateForOrder(SalesOrder::query()->findOrFail($id), $this->userId($request));

        return new WorkspaceSalesOrderResource($this->detail($id));
    }

    #[OA\Post(
        path: '/api/v1/sales-orders/{id}/reevaluate-atp',
        operationId: 'reevaluateAtpWorkspaceSalesOrder',
        description: 'Recalculates the ATP/CTP promise of the open lines of the order and stores it on the lines.',
        summary: 'Re-evaluate ATP',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Orders Workspace'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'ATP recalculated', content: new OA\JsonContent(type: 'object'))]
    )]
    public function reevaluateAtp(Request $request, string $id): WorkspaceSalesOrderResource
    {
        $this->fulfillment->annotateAtp(SalesOrder::query()->findOrFail($id), $this->userId($request));

        return new WorkspaceSalesOrderResource($this->detail($id));
    }

    #[OA\Post(
        path: '/api/v1/sales-orders/{id}/create-ddt',
        operationId: 'createDdtWorkspaceSalesOrder',
        description: 'Creates and issues a delivery note for the quantities given (line id => quantity, all open quantities when empty). The residual of a partially shipped line is kept open, split into a backorder line with a recalculated ATP date, or cancelled.',
        summary: 'Ship sales order (partial or full DDT)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Orders Workspace'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 201, description: 'Delivery note created', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 422, description: 'Order blocked, not confirmed or quantity above the open quantity', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function createDdt(CreateDdtRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $lines = $validated['lines'] ?? [];
        $residual = (string) ($validated['residual_mode'] ?? SalesOrderFulfillmentService::KeepOpen);
        unset($validated['lines'], $validated['residual_mode']);

        $note = $this->fulfillment->ship(SalesOrder::query()->findOrFail($id), $lines, $validated, $residual, $this->userId($request));

        return (new SalesDeliveryNoteResource($note->load('items')))->response()->setStatusCode(201);
    }

    #[OA\Post(
        path: '/api/v1/sales-orders/{id}/cancel',
        operationId: 'cancelWorkspaceSalesOrder',
        description: 'Cancels the order: releases its stock reservations and gives the undelivered quantity back to its blanket agreement.',
        summary: 'Cancel sales order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Orders Workspace'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Order cancelled', content: new OA\JsonContent(type: 'object'))]
    )]
    public function cancel(CancelWorkspaceOrderRequest $request, string $id): WorkspaceSalesOrderResource
    {
        $this->orders->cancelOrder(SalesOrder::query()->findOrFail($id), (string) $request->validated('reason'), $this->userId($request) ?? 'system');

        return new WorkspaceSalesOrderResource($this->detail($id));
    }

    #[OA\Get(
        path: '/api/v1/sales-orders/backorders',
        operationId: 'listWorkspaceBackorders',
        description: 'Backorder lines and open lines whose ATP promise is late or short, for the supply chain planners.',
        summary: 'Backorder and shortage monitor',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Orders Workspace'],
        parameters: [new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object'))]
    )]
    public function backorders(Request $request): JsonResponse
    {
        $page = $this->fulfillment->backorderMonitor((string) $this->tenantContext->getTenantId(), min(100, max(1, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => collect($page->items())->map(static fn (SalesOrderItem $item): array => [
                'sales_order_item_id' => $item->id,
                'sales_order_id' => $item->sales_order_id,
                'order_number' => $item->order?->order_number,
                'customer_name' => $item->order?->customer?->company_name,
                'product_id' => $item->product_id,
                'description' => $item->description,
                'is_backorder' => $item->is_backorder,
                'open_quantity' => bcsub(bcsub((string) $item->quantity, (string) $item->quantity_delivered, 4), (string) $item->quantity_cancelled, 4),
                'reserved_quantity' => (string) $item->quantity_reserved,
                'atp_status' => $item->atp_status,
                'atp_promised_date' => $item->atp_promised_date?->toDateString(),
                'requested_date' => $item->requested_date?->toDateString(),
                'replenishment_triggered' => $item->replenishment_triggered,
                'replenishment_reference_type' => $item->replenishment_reference_type,
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    private function detail(string $id): SalesOrder
    {
        return SalesOrder::query()->with(['customer', 'blanketAgreement', 'items', 'deliveryNotes', 'replenishmentRequests', 'auditLogs'])->findOrFail($id);
    }

    private function userId(Request $request): ?string
    {
        return $request->user()?->id !== null ? (string) $request->user()->id : null;
    }
}
