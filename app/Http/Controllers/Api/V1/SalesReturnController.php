<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ReceiveSalesReturnRequest;
use App\Http\Requests\Sales\RejectSalesReturnRequest;
use App\Http\Requests\Sales\StoreSalesReturnRequest;
use App\Http\Resources\SalesReturnResource;
use App\Http\Responses\ApiResponse;
use App\Models\SalesReturn;
use App\Services\Sales\SalesReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sales returns (RMA): request, authorisation by a sales manager, receipt in the warehouse with stock re-entry, and the
 * credit note on the original invoice.
 */
#[OA\Tag(name: 'Sales Returns', description: 'Returns of sold goods (RMA): authorisation, receipt with stock re-entry, credit note')]
class SalesReturnController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SalesReturnService $returns) {}

    /**
     * Lists the returns of the company.
     */
    #[OA\Get(
        path: '/api/v1/sales/returns',
        operationId: 'listSalesReturns',
        description: 'Lists the returns of the active company, newest first, optionally by status or customer.',
        summary: 'List sales returns',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['requested', 'approved', 'received', 'credited', 'rejected', 'cancelled'])),
            new OA\Parameter(name: 'contact_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(ref: '#/components/parameters/SalesCyclePage'),
            new OA\Parameter(ref: '#/components/parameters/SalesCyclePerPage'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Returns', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SalesCycleReturn')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $returns = SalesReturn::query()
            ->with('contact')
            ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
            ->when($request->filled('contact_id'), static fn ($query) => $query->where('contact_id', (string) $request->query('contact_id')))
            ->latest('date')
            ->latest('return_number')
            ->gridPaginate(min(100, max(5, (int) $request->query('per_page', 15))));

        return $this->successResponse(SalesReturnResource::collection($returns), __('sales.messages.returns_list'), meta: [
            'current_page' => $returns->currentPage(),
            'last_page' => $returns->lastPage(),
            'per_page' => $returns->perPage(),
            'total' => $returns->total(),
        ]);
    }

    /**
     * Records a return request.
     */
    #[OA\Post(
        path: '/api/v1/sales/returns',
        operationId: 'createSalesReturn',
        description: 'Records a return request on an issued delivery note or a posted invoice of the active company. Each line names the line of the source document and is priced as it was sold; a line can be returned up to what was delivered (or invoiced) less what other returns already took back. The return is numbered on the sales_return series (RMA-).',
        summary: 'Create sales return',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SalesCycleReturnRequest')),
        tags: ['Sales Returns'],
        responses: [
            new OA\Response(response: 201, description: 'Return requested', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/SalesCycleReturn'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function store(StoreSalesReturnRequest $request): JsonResponse
    {
        $return = $this->returns->create($request->validated(), $this->userId());

        return $this->successResponse(new SalesReturnResource($return), __('sales.messages.return_created'), Response::HTTP_CREATED);
    }

    /**
     * A return with its lines.
     */
    #[OA\Get(
        path: '/api/v1/sales/returns/{id}',
        operationId: 'getSalesReturn',
        description: 'Returns a sales return of the active company with its lines.',
        summary: 'Get sales return',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Returns'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleReturnId')],
        responses: [
            new OA\Response(response: 200, description: 'Return', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/SalesCycleReturn'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new SalesReturnResource(SalesReturn::query()->with(['items', 'contact'])->findOrFail($id)), __('sales.messages.return_retrieved'));
    }

    /**
     * Authorises a requested return.
     */
    #[OA\Post(
        path: '/api/v1/sales/returns/{id}/approve',
        operationId: 'approveSalesReturn',
        description: 'Authorises a requested return (sales managers only: owner, admin, sales_manager). The warehouse can then receive it.',
        summary: 'Approve sales return',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Returns'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleReturnId')],
        responses: [
            new OA\Response(response: 200, description: 'Return approved', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/SalesCycleReturn'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function approve(string $id): JsonResponse
    {
        $return = SalesReturn::query()->findOrFail($id);
        $this->authorize('approve', $return);

        return $this->successResponse(new SalesReturnResource($this->returns->approve($return, $this->userId())), __('sales.messages.return_approved'));
    }

    /**
     * Refuses a requested return.
     */
    #[OA\Post(
        path: '/api/v1/sales/returns/{id}/reject',
        operationId: 'rejectSalesReturn',
        description: 'Refuses a requested return with a reason (sales managers only). A rejected return is final and frees the quantities for other returns.',
        summary: 'Reject sales return',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SalesCycleRejectReturnRequest')),
        tags: ['Sales Returns'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleReturnId')],
        responses: [
            new OA\Response(response: 200, description: 'Return rejected', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/SalesCycleReturn'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function reject(RejectSalesReturnRequest $request, string $id): JsonResponse
    {
        $return = SalesReturn::query()->findOrFail($id);
        $this->authorize('approve', $return);

        return $this->successResponse(new SalesReturnResource($this->returns->reject($return, (string) $request->validated('reason'))), __('sales.messages.return_rejected'));
    }

    /**
     * Cancels a return not received yet.
     */
    #[OA\Post(
        path: '/api/v1/sales/returns/{id}/cancel',
        operationId: 'cancelSalesReturn',
        description: 'Cancels a requested or approved return that was not received yet (the customer kept the goods).',
        summary: 'Cancel sales return',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Returns'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleReturnId')],
        responses: [
            new OA\Response(response: 200, description: 'Return cancelled', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/SalesCycleReturn'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        return $this->successResponse(new SalesReturnResource($this->returns->cancel(SalesReturn::query()->findOrFail($id))), __('sales.messages.return_cancelled'));
    }

    /**
     * Receives an authorised return in the warehouse.
     */
    #[OA\Post(
        path: '/api/v1/sales/returns/{id}/receive',
        operationId: 'receiveSalesReturn',
        description: 'Receives an approved return: every line in full, or the quantities given per line. Resellable lines of products with inventory tracking marked for restock go back into the stock of the warehouse (customer return movement in the inventory module); damaged goods are not restocked.',
        summary: 'Receive sales return',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/SalesCycleReceiveReturnRequest')),
        tags: ['Sales Returns'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleReturnId')],
        responses: [
            new OA\Response(response: 200, description: 'Return received', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/SalesCycleReturn'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function receive(ReceiveSalesReturnRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();
        $return = $this->returns->receive(SalesReturn::query()->findOrFail($id), $data['items'] ?? [], $data['warehouse_id'] ?? null);

        return $this->successResponse(new SalesReturnResource($return), __('sales.messages.return_received'));
    }

    /**
     * Credits a received return.
     */
    #[OA\Post(
        path: '/api/v1/sales/returns/{id}/credit-note',
        operationId: 'creditSalesReturn',
        description: 'Issues, through the invoicing module, one credit note on the original invoice for the received quantities at the price they were sold (the invoice named on the return, or the one that invoiced its delivery note lines). The return becomes credited and links the credit note.',
        summary: 'Credit sales return',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Returns'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleReturnId')],
        responses: [
            new OA\Response(response: 200, description: 'Return credited', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/SalesCycleReturn'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function credit(string $id): JsonResponse
    {
        return $this->successResponse(new SalesReturnResource($this->returns->credit(SalesReturn::query()->findOrFail($id), $this->userId())), __('sales.messages.return_credited'));
    }

    private function userId(): ?string
    {
        return auth()->id() !== null ? (string) auth()->id() : null;
    }
}
