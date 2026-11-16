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
use Modules\Returns\Http\Requests\SupplierReturnCreditRequest;
use Modules\Returns\Http\Requests\SupplierReturnRequest;
use Modules\Returns\Http\Requests\SupplierReturnShipRequest;
use Modules\Returns\Http\Resources\SupplierReturnResource;
use Modules\Returns\Models\SupplierReturn;
use Modules\Returns\Services\SupplierReturnService;
use OpenApi\Attributes as OA;

/**
 * Returns to the supplier (RTV): draft, shipment with tracking, credit and closing.
 */
final class SupplierReturnController extends Controller
{
    use InteractsWithReturns;

    public function __construct(private readonly SupplierReturnService $returns) {}

    #[OA\Get(path: '/api/v1/returns/supplier-returns', operationId: 'listReturnsSupplierReturns', description: 'Returns to the supplier, newest first. Filters: status, supplier_id, search (return or tracking number).', summary: 'List supplier returns', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Supplier returns', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ReturnsSupplierReturn'))])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SupplierReturn::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(SupplierReturn::Statuses)],
            'supplier_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return SupplierReturnResource::collection(
            SupplierReturn::query()
                ->with(['lines', 'supplier'])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['supplier_id']), static fn ($query) => $query->where('supplier_id', $filters['supplier_id']))
                ->when(isset($filters['search']), static fn ($query) => $query->where(static fn ($inner) => $inner
                    ->where('return_number', 'like', '%'.$filters['search'].'%')
                    ->orWhere('tracking_number', 'like', '%'.$filters['search'].'%')))
                ->orderByDesc('created_at')
                ->orderByDesc('return_number')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/returns/supplier-returns', operationId: 'storeReturnsSupplierReturn', description: 'Draft a return to a supplier. A line tied to a goods receipt line cannot exceed what that receipt brought in less what other supplier returns took back. The expected credit defaults to the value of the lines.', summary: 'Draft a supplier return', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsSupplierReturnRequest')), tags: ['Returns'], responses: [
        new OA\Response(response: 201, description: 'Supplier return drafted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsSupplierReturn')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function store(SupplierReturnRequest $request): JsonResponse
    {
        $this->authorize('create', SupplierReturn::class);
        $return = $this->returns->create($this->tenantId(), $request->validated(), $this->userId());

        return (new SupplierReturnResource($return->load('supplier')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/returns/supplier-returns/{id}', operationId: 'showReturnsSupplierReturn', description: 'Return a supplier return with its lines.', summary: 'Show a supplier return', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Supplier return', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsSupplierReturn')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
    ])]
    public function show(string $id): SupplierReturnResource
    {
        $return = SupplierReturn::query()->with(['lines', 'supplier'])->findOrFail($id);
        $this->authorize('view', $return);

        return new SupplierReturnResource($return);
    }

    #[OA\Put(path: '/api/v1/returns/supplier-returns/{id}', operationId: 'updateReturnsSupplierReturn', description: 'Change a supplier return. Only a draft changes its supplier, lines and expected credit; the carrier, tracking number and notes change until it is closed.', summary: 'Update a supplier return', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsSupplierReturnRequest')), tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Supplier return updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsSupplierReturn')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function update(SupplierReturnRequest $request, string $id): SupplierReturnResource
    {
        $return = SupplierReturn::query()->findOrFail($id);
        $this->authorize('update', $return);

        return new SupplierReturnResource($this->returns->update($return, $request->validated())->load('supplier'));
    }

    #[OA\Post(path: '/api/v1/returns/supplier-returns/{id}/ship', operationId: 'shipReturnsSupplierReturn', description: 'Ship a draft return: the lines leave the warehouse through the inventory stock service and the carrier and tracking number are recorded.', summary: 'Ship a supplier return', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsSupplierReturnShipRequest')), tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Supplier return shipped', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsSupplierReturn')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function ship(SupplierReturnShipRequest $request, string $id): SupplierReturnResource
    {
        $return = SupplierReturn::query()->findOrFail($id);
        $this->authorize('update', $return);

        return new SupplierReturnResource($this->returns->ship($return, $request->validated())->load('supplier'));
    }

    #[OA\Post(path: '/api/v1/returns/supplier-returns/{id}/credit', operationId: 'creditReturnsSupplierReturn', description: 'Record the credit the supplier granted for a shipped return.', summary: 'Record the supplier credit', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsSupplierReturnCreditRequest')), tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Credit recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsSupplierReturn')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function credit(SupplierReturnCreditRequest $request, string $id): SupplierReturnResource
    {
        $return = SupplierReturn::query()->findOrFail($id);
        $this->authorize('update', $return);

        return new SupplierReturnResource($this->returns->credit($return, (string) $request->validated()['credit_received'])->load('supplier'));
    }

    #[OA\Post(path: '/api/v1/returns/supplier-returns/{id}/close', operationId: 'closeReturnsSupplierReturn', description: 'Close a shipped or credited return. A closed return is final.', summary: 'Close a supplier return', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Supplier return closed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsSupplierReturn')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function close(string $id): SupplierReturnResource
    {
        $return = SupplierReturn::query()->findOrFail($id);
        $this->authorize('update', $return);

        return new SupplierReturnResource($this->returns->close($return)->load('supplier'));
    }

    #[OA\Post(path: '/api/v1/returns/supplier-returns/{id}/cancel', operationId: 'cancelReturnsSupplierReturn', description: 'Cancel a draft return.', summary: 'Cancel a supplier return', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Supplier return cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsSupplierReturn')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function cancel(string $id): SupplierReturnResource
    {
        $return = SupplierReturn::query()->findOrFail($id);
        $this->authorize('update', $return);

        return new SupplierReturnResource($this->returns->cancel($return)->load('supplier'));
    }
}
