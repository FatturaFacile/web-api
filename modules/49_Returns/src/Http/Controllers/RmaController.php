<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Returns\Http\Controllers\Concerns\InteractsWithReturns;
use Modules\Returns\Http\Requests\ReasonRequest;
use Modules\Returns\Http\Requests\RmaApproveRequest;
use Modules\Returns\Http\Requests\RmaInspectRequest;
use Modules\Returns\Http\Requests\RmaReceiveRequest;
use Modules\Returns\Http\Requests\RmaRequest;
use Modules\Returns\Http\Requests\RmaResolveRequest;
use Modules\Returns\Http\Resources\RmaResource;
use Modules\Returns\Models\Rma;
use Modules\Returns\Policies\ReturnsPolicy;
use Modules\Returns\Services\RmaService;
use OpenApi\Attributes as OA;

/**
 * Customer returns (RMA): request, authorisation, receipt, inspection with disposition, resolution and closing.
 */
final class RmaController extends Controller
{
    use InteractsWithReturns;

    public function __construct(private readonly RmaService $rmas) {}

    #[OA\Get(path: '/api/v1/returns/rmas', operationId: 'listReturnsRmas', description: 'Customer returns, newest first. Filters: status, contact_id, reason_code, search (RMA number), from and to (request date).', summary: 'List customer returns', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Returns', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ReturnsRma'))])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Rma::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Rma::Statuses)],
            'contact_id' => ['nullable', 'uuid'],
            'reason_code' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return RmaResource::collection(
            Rma::query()
                ->with(['lines', 'contact', 'invoice'])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['contact_id']), static fn ($query) => $query->where('contact_id', $filters['contact_id']))
                ->when(isset($filters['reason_code']), static fn ($query) => $query->where('reason_code', $filters['reason_code']))
                ->when(isset($filters['search']), static fn ($query) => $query->where('rma_number', 'like', '%'.$filters['search'].'%'))
                ->when(isset($filters['from']), static fn ($query) => $query->where('requested_on', '>=', $filters['from']))
                ->when(isset($filters['to']), static fn ($query) => $query->where('requested_on', '<=', $filters['to']))
                ->orderByDesc('requested_on')
                ->orderByDesc('rma_number')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Get(path: '/api/v1/returns/sources', operationId: 'listReturnsSources', description: 'The latest invoices and the confirmed sales orders of a customer a return can be requested against, with the quantity of every line that can still be returned (sold less what other returns took back) and whether the return window of the settings is still open.', summary: 'Documents a customer can return against', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], parameters: [
        new OA\Parameter(name: 'contact_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Sources', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ReturnsSource'))])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function sources(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Rma::class);
        $filters = $request->validate(['contact_id' => ['required', 'uuid']]);

        return response()->json(['data' => $this->rmas->sources($this->tenantId(), $filters['contact_id'])]);
    }

    #[OA\Post(path: '/api/v1/returns/rmas', operationId: 'storeReturnsRma', description: 'Request a return against an invoice or a sales order of the customer. Every line names a source line and cannot exceed what it sold less what was already returned. A request after the return window of the settings is refused unless an approver sets override_window.', summary: 'Request a customer return', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsRmaRequest')), tags: ['Returns'], responses: [
        new OA\Response(response: 201, description: 'Return requested', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsRma')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function store(RmaRequest $request): JsonResponse
    {
        $this->authorize('create', Rma::class);
        $rma = $this->rmas->create($this->tenantId(), $request->validated(), $this->userId(), app(ReturnsPolicy::class)->mayApprove($request->user()));

        return (new RmaResource($this->fresh($rma)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/returns/rmas/{id}', operationId: 'showReturnsRma', description: 'Return a customer return with its lines.', summary: 'Show a customer return', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Return', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsRma')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
    ])]
    public function show(string $id): RmaResource
    {
        $rma = Rma::query()->findOrFail($id);
        $this->authorize('view', $rma);

        return new RmaResource($this->fresh($rma));
    }

    #[OA\Put(path: '/api/v1/returns/rmas/{id}', operationId: 'updateReturnsRma', description: 'Change a return while it is requested: reason, date, notes and lines. The customer and the source document are fixed.', summary: 'Update a customer return', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsRmaRequest')), tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Return updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsRma')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function update(RmaRequest $request, string $id): RmaResource
    {
        $rma = Rma::query()->findOrFail($id);
        $this->authorize('update', $rma);

        return new RmaResource($this->fresh($this->rmas->update($rma, $request->validated(), app(ReturnsPolicy::class)->mayApprove($request->user()))));
    }

    #[OA\Post(path: '/api/v1/returns/rmas/{id}/approve', operationId: 'approveReturnsRma', description: 'Authorise a requested return. Reserved to owners, administrators and members granted returns.approve.', summary: 'Approve a customer return', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsRmaApproveRequest')), tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Return approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsRma')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function approve(RmaApproveRequest $request, string $id): RmaResource
    {
        $rma = Rma::query()->findOrFail($id);
        $this->authorize('approve', $rma);

        return new RmaResource($this->fresh($this->rmas->approve($rma, $this->userId(), $request->validated()['warehouse_id'] ?? null)));
    }

    #[OA\Post(path: '/api/v1/returns/rmas/{id}/reject', operationId: 'rejectReturnsRma', description: 'Reject a return that is requested, approved or inspected. Reserved to approvers.', summary: 'Reject a customer return', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsReasonRequest')), tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Return rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsRma')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function reject(ReasonRequest $request, string $id): RmaResource
    {
        $rma = Rma::query()->findOrFail($id);
        $this->authorize('approve', $rma);

        return new RmaResource($this->fresh($this->rmas->reject($rma, (string) $request->validated()['reason'])));
    }

    #[OA\Post(path: '/api/v1/returns/rmas/{id}/receive', operationId: 'receiveReturnsRma', description: 'The goods arrive in a warehouse: every line is received in full unless the request gives the quantity received.', summary: 'Receive a customer return', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsRmaReceiveRequest')), tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Return received', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsRma')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function receive(RmaReceiveRequest $request, string $id): RmaResource
    {
        $rma = Rma::query()->findOrFail($id);
        $this->authorize('update', $rma);

        return new RmaResource($this->fresh($this->rmas->receive($rma, $request->validated())));
    }

    #[OA\Post(path: '/api/v1/returns/rmas/{id}/inspect', operationId: 'inspectReturnsRma', description: 'Inspect the received goods and decide the disposition of every received line: restock and return_to_supplier book the stock movement through the inventory module (return_to_supplier also drafts the supplier return), scrap and refurbish move no stock.', summary: 'Inspect a customer return', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsRmaInspectRequest')), tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Return inspected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsRma')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function inspect(RmaInspectRequest $request, string $id): RmaResource
    {
        $rma = Rma::query()->findOrFail($id);
        $this->authorize('update', $rma);

        return new RmaResource($this->fresh($this->rmas->inspect($rma, $request->validated(), $this->userId())));
    }

    #[OA\Post(path: '/api/v1/returns/rmas/{id}/resolve', operationId: 'resolveReturnsRma', description: 'Resolve an inspected return with a refund, a credit note (its draft reference), a replacement (the goods leave the warehouse) or a repair. The refund defaults to the value of the inspected goods at the price they were sold at.', summary: 'Resolve a customer return', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsRmaResolveRequest')), tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Return resolved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsRma')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function resolve(RmaResolveRequest $request, string $id): RmaResource
    {
        $rma = Rma::query()->findOrFail($id);
        $this->authorize('approve', $rma);

        return new RmaResource($this->fresh($this->rmas->resolve($rma, $request->validated())));
    }

    #[OA\Post(path: '/api/v1/returns/rmas/{id}/close', operationId: 'closeReturnsRma', description: 'Close a resolved or rejected return. A closed return is final.', summary: 'Close a customer return', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Return closed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsRma')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function close(string $id): RmaResource
    {
        $rma = Rma::query()->findOrFail($id);
        $this->authorize('update', $rma);

        return new RmaResource($this->fresh($this->rmas->close($rma)));
    }

    private function fresh(Rma $rma): Rma
    {
        return $rma->load(['lines', 'contact', 'invoice']);
    }
}
