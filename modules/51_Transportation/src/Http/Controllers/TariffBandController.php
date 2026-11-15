<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Transportation\Http\Controllers\Concerns\InteractsWithTransportation;
use Modules\Transportation\Http\Requests\TariffBandRequest;
use Modules\Transportation\Http\Resources\TariffBandResource;
use Modules\Transportation\Models\TmsTariffBand;
use Modules\Transportation\Services\TariffBandService;
use OpenApi\Attributes as OA;

/**
 * Tariff bands of the active company.
 */
final class TariffBandController extends Controller
{
    use InteractsWithTransportation;

    public function __construct(private readonly TariffBandService $service) {}

    #[OA\Get(path: '/api/v1/transportation/tariff-bands', operationId: 'listTransportationTariffBands', description: 'Tariff bands of the active company. Filters: tariff_table_id, origin_zone, destination_zone.', summary: 'List tariff bands', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'tariff_table_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'origin_zone', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'destination_zone', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Tariff bands', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TransportationTariffBand'))])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TmsTariffBand::class);
        $filters = $request->validate(['tariff_table_id' => ['nullable', 'uuid'], 'origin_zone' => ['nullable', 'string', 'max:100'], 'destination_zone' => ['nullable', 'string', 'max:100']]);

        return TariffBandResource::collection(
            TmsTariffBand::query()
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['tariff_table_id']), static fn ($query) => $query->where('tariff_table_id', $filters['tariff_table_id']))
                ->when(isset($filters['origin_zone']), static fn ($query) => $query->where('origin_zone', $filters['origin_zone']))
                ->when(isset($filters['destination_zone']), static fn ($query) => $query->where('destination_zone', $filters['destination_zone']))
                ->orderBy('weight_from_kg')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/transportation/tariff-bands', operationId: 'storeTransportationTariffBand', description: 'Create a tariff band.', summary: 'Create a tariff band', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TransportationTariffBandRequest')), responses: [
        new OA\Response(response: 201, description: 'Tariff band created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTariffBand')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function store(TariffBandRequest $request): JsonResponse
    {
        $this->authorize('create', TmsTariffBand::class);

        return (new TariffBandResource($this->service->create($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/transportation/tariff-bands/{id}', operationId: 'showTransportationTariffBand', description: 'Return a tariff band.', summary: 'Show a tariff band', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 200, description: 'Tariff band', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTariffBand')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
    ])]
    public function show(string $id): TariffBandResource
    {
        $record = TmsTariffBand::query()->findOrFail($id);
        $this->authorize('view', $record);

        return new TariffBandResource($record);
    }

    #[OA\Put(path: '/api/v1/transportation/tariff-bands/{id}', operationId: 'updateTransportationTariffBand', description: 'Change a tariff band; only the fields sent are validated and changed.', summary: 'Update a tariff band', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TransportationTariffBandRequest')), responses: [
        new OA\Response(response: 200, description: 'Tariff band updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTariffBand')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function update(TariffBandRequest $request, string $id): TariffBandResource
    {
        $record = TmsTariffBand::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new TariffBandResource($this->service->update($record, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/transportation/tariff-bands/{id}', operationId: 'deleteTransportationTariffBand', description: 'Delete a tariff band (soft delete).', summary: 'Delete a tariff band', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 204, description: 'Tariff band deleted'),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $record = TmsTariffBand::query()->findOrFail($id);
        $this->authorize('delete', $record);
        $this->service->delete($record);

        return response()->noContent();
    }
}
