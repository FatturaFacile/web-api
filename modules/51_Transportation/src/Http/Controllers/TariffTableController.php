<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Transportation\Http\Controllers\Concerns\InteractsWithTransportation;
use Modules\Transportation\Http\Requests\TariffTableRequest;
use Modules\Transportation\Http\Resources\TariffTableResource;
use Modules\Transportation\Models\TmsTariffTable;
use Modules\Transportation\Services\TariffTableService;
use OpenApi\Attributes as OA;

/**
 * Tariff tables of the active company.
 */
final class TariffTableController extends Controller
{
    use InteractsWithTransportation;

    public function __construct(private readonly TariffTableService $service) {}

    #[OA\Get(path: '/api/v1/transportation/tariff-tables', operationId: 'listTransportationTariffTables', description: 'Tariff tables of the active company. Filters: carrier_id, rate_basis, is_active, search.', summary: 'List tariff tables', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'carrier_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'rate_basis', in: 'query', schema: new OA\Schema(type: 'string', enum: ['flat', 'weight', 'distance', 'weight_distance'])), new OA\Parameter(name: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')), new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Tariff tables', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TransportationTariffTable'))])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TmsTariffTable::class);
        $filters = $request->validate(['carrier_id' => ['nullable', 'uuid'], 'rate_basis' => ['nullable', Rule::in(TmsTariffTable::RateBases)], 'is_active' => ['nullable', 'boolean'], 'search' => ['nullable', 'string', 'max:100']]);

        return TariffTableResource::collection(
            TmsTariffTable::query()
                ->with(['carrier:id,code,name'])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['carrier_id']), static fn ($query) => $query->where('carrier_id', $filters['carrier_id']))
                ->when(isset($filters['rate_basis']), static fn ($query) => $query->where('rate_basis', $filters['rate_basis']))
                ->when(isset($filters['is_active']), static fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
                ->when(isset($filters['search']), static function ($query) use ($filters): void {
                    $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
                    $query->where(static fn ($inner) => $inner->where('name', 'like', $term));
                })
                ->orderByDesc('created_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/transportation/tariff-tables', operationId: 'storeTransportationTariffTable', description: 'Create a tariff table.', summary: 'Create a tariff table', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TransportationTariffTableRequest')), responses: [
        new OA\Response(response: 201, description: 'Tariff table created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTariffTable')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function store(TariffTableRequest $request): JsonResponse
    {
        $this->authorize('create', TmsTariffTable::class);

        return (new TariffTableResource($this->service->create($this->tenantId(), $request->validated())->load(['carrier:id,code,name'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/transportation/tariff-tables/{id}', operationId: 'showTransportationTariffTable', description: 'Return a tariff table.', summary: 'Show a tariff table', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 200, description: 'Tariff table', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTariffTable')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
    ])]
    public function show(string $id): TariffTableResource
    {
        $record = TmsTariffTable::query()->with(['carrier:id,code,name'])->findOrFail($id);
        $this->authorize('view', $record);

        return new TariffTableResource($record);
    }

    #[OA\Put(path: '/api/v1/transportation/tariff-tables/{id}', operationId: 'updateTransportationTariffTable', description: 'Change a tariff table; only the fields sent are validated and changed.', summary: 'Update a tariff table', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TransportationTariffTableRequest')), responses: [
        new OA\Response(response: 200, description: 'Tariff table updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTariffTable')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function update(TariffTableRequest $request, string $id): TariffTableResource
    {
        $record = TmsTariffTable::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new TariffTableResource($this->service->update($record, $request->validated())->load(['carrier:id,code,name']));
    }

    #[OA\Delete(path: '/api/v1/transportation/tariff-tables/{id}', operationId: 'deleteTransportationTariffTable', description: 'Delete a tariff table with its bands (soft delete).', summary: 'Delete a tariff table', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 204, description: 'Tariff table deleted'),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $record = TmsTariffTable::query()->findOrFail($id);
        $this->authorize('delete', $record);
        $this->service->delete($record);

        return response()->noContent();
    }
}
