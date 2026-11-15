<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Sustainability\Http\Controllers\Concerns\InteractsWithSustainability;
use Modules\Sustainability\Http\Requests\ReceiveSupplierDataRequest;
use Modules\Sustainability\Http\Requests\SupplierDataRequestRequest;
use Modules\Sustainability\Http\Resources\SupplierDataRequestResource;
use Modules\Sustainability\Models\EsgSupplierDataRequest;
use Modules\Sustainability\Services\SupplierDataRequestService;
use OpenApi\Attributes as OA;

/**
 * Requests of supplier-specific emission data for Scope 3, carried by the supplier portal document requirements.
 */
final class SupplierDataRequestController extends Controller
{
    use InteractsWithSustainability;

    public function __construct(private readonly SupplierDataRequestService $requests) {}

    #[OA\Get(path: '/api/v1/sustainability/supplier-requests', operationId: 'listEsgSupplierDataRequests', description: 'Supplier-specific emission data requests, newest first. Filters: reporting_period_id, supplier_id, status.', summary: 'List supplier data requests', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [
        new OA\Parameter(name: 'reporting_period_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'supplier_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['requested', 'received', 'applied', 'declined', 'cancelled'])),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Requests', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SustainabilitySupplierRequest'))])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', EsgSupplierDataRequest::class);

        return SupplierDataRequestResource::collection(
            EsgSupplierDataRequest::query()
                ->with('supplier')
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('reporting_period_id'), static fn ($query) => $query->where('reporting_period_id', (string) $request->query('reporting_period_id')))
                ->when($request->filled('supplier_id'), static fn ($query) => $query->where('supplier_id', (string) $request->query('supplier_id')))
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->orderByDesc('created_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/sustainability/supplier-requests', operationId: 'storeEsgSupplierDataRequest', description: 'Asks a supplier for its own emission data (kg CO2e) for the quantity purchased in a Scope 3 category. The request uses the supplier portal document requirement ESG_SUPPLIER_EMISSIONS, created on first use.', summary: 'Request supplier-specific emission data', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilitySupplierRequestPayload')), tags: ['Sustainability'], responses: [
        new OA\Response(response: 201, description: 'Request created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilitySupplierRequest')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function store(SupplierDataRequestRequest $request): JsonResponse
    {
        $this->authorize('create', EsgSupplierDataRequest::class);

        return (new SupplierDataRequestResource($this->requests->create($this->tenantId(), $request->validated(), $this->userId())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/sustainability/supplier-requests/{id}', operationId: 'showEsgSupplierDataRequest', description: 'A supplier data request.', summary: 'Show a supplier data request', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilitySupplierRequest')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
    ])]
    public function show(string $id): SupplierDataRequestResource
    {
        $request = $this->find($id);
        $this->authorize('view', $request);

        return new SupplierDataRequestResource($request);
    }

    #[OA\Post(path: '/api/v1/sustainability/supplier-requests/{id}/receive', operationId: 'receiveEsgSupplierDataRequest', description: 'Registers the emissions the supplier reported for the requested quantity, optionally linking the supplier portal submission that carries its statement.', summary: 'Register supplier data', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilitySupplierReceivePayload')), tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Data registered', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilitySupplierRequest')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function receive(ReceiveSupplierDataRequest $request, string $id): SupplierDataRequestResource
    {
        $record = $this->find($id);
        $this->authorize('update', $record);

        return new SupplierDataRequestResource($this->requests->receive($record, $request->validated()));
    }

    #[OA\Post(path: '/api/v1/sustainability/supplier-requests/{id}/apply', operationId: 'applyEsgSupplierDataRequest', description: 'Turns the received data into a supplier-specific emission factor and an activity record of the period, so it enters the Scope 3 inventory. The period must be open.', summary: 'Apply supplier data to the inventory', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Data applied', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilitySupplierRequest')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function apply(string $id): SupplierDataRequestResource
    {
        $record = $this->find($id);
        $this->authorize('update', $record);

        return new SupplierDataRequestResource($this->requests->apply($record, $this->userId()));
    }

    #[OA\Post(path: '/api/v1/sustainability/supplier-requests/{id}/decline', operationId: 'declineEsgSupplierDataRequest', description: 'Records that the supplier declined to provide data (the category keeps using spend-based factors).', summary: 'Decline a supplier data request', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Request declined', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilitySupplierRequest')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function decline(string $id): SupplierDataRequestResource
    {
        $record = $this->find($id);
        $this->authorize('update', $record);

        return new SupplierDataRequestResource($this->requests->decline($record));
    }

    #[OA\Post(path: '/api/v1/sustainability/supplier-requests/{id}/cancel', operationId: 'cancelEsgSupplierDataRequest', description: 'Withdraws a request that is no longer needed.', summary: 'Cancel a supplier data request', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Request cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilitySupplierRequest')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function cancel(string $id): SupplierDataRequestResource
    {
        $record = $this->find($id);
        $this->authorize('update', $record);

        return new SupplierDataRequestResource($this->requests->cancel($record));
    }

    private function find(string $id): EsgSupplierDataRequest
    {
        return EsgSupplierDataRequest::query()->with('supplier')->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
