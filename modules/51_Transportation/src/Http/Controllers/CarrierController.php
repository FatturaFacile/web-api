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
use Modules\Transportation\Http\Requests\CarrierRequest;
use Modules\Transportation\Http\Resources\CarrierResource;
use Modules\Transportation\Models\TmsCarrier;
use Modules\Transportation\Services\CarrierService;
use OpenApi\Attributes as OA;

/**
 * Carriers of the active company.
 */
final class CarrierController extends Controller
{
    use InteractsWithTransportation;

    public function __construct(private readonly CarrierService $service) {}

    #[OA\Get(path: '/api/v1/transportation/carriers', operationId: 'listTransportationCarriers', description: 'Carriers of the active company. Filters: mode, is_active, search.', summary: 'List carriers', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'mode', in: 'query', schema: new OA\Schema(type: 'string', enum: ['road', 'air', 'sea', 'rail', 'courier'])), new OA\Parameter(name: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')), new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Carriers', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TransportationCarrier'))])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TmsCarrier::class);
        $filters = $request->validate(['mode' => ['nullable', Rule::in(TmsCarrier::Modes)], 'is_active' => ['nullable', 'boolean'], 'search' => ['nullable', 'string', 'max:100']]);

        return CarrierResource::collection(
            TmsCarrier::query()
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['mode']), static fn ($query) => $query->where('mode', $filters['mode']))
                ->when(isset($filters['is_active']), static fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
                ->when(isset($filters['search']), static function ($query) use ($filters): void {
                    $term = '%'.mb_strtolower(str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $filters['search'])).'%';
                    $query->where(static fn ($inner) => $inner
                        ->whereRaw("LOWER(COALESCE(code, '')) LIKE ? ESCAPE '!'", [$term])
                        ->orWhereRaw("LOWER(COALESCE(name, '')) LIKE ? ESCAPE '!'", [$term]));
                })
                ->orderBy('name')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/transportation/carriers', operationId: 'storeTransportationCarrier', description: 'Create a carrier.', summary: 'Create a carrier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TransportationCarrierRequest')), responses: [
        new OA\Response(response: 201, description: 'Carrier created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationCarrier')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function store(CarrierRequest $request): JsonResponse
    {
        $this->authorize('create', TmsCarrier::class);

        return (new CarrierResource($this->service->create($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/transportation/carriers/{id}', operationId: 'showTransportationCarrier', description: 'Return a carrier.', summary: 'Show a carrier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 200, description: 'Carrier', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationCarrier')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
    ])]
    public function show(string $id): CarrierResource
    {
        $record = TmsCarrier::query()->findOrFail($id);
        $this->authorize('view', $record);

        return new CarrierResource($record);
    }

    #[OA\Put(path: '/api/v1/transportation/carriers/{id}', operationId: 'updateTransportationCarrier', description: 'Change a carrier; only the fields sent are validated and changed.', summary: 'Update a carrier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TransportationCarrierRequest')), responses: [
        new OA\Response(response: 200, description: 'Carrier updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationCarrier')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function update(CarrierRequest $request, string $id): CarrierResource
    {
        $record = TmsCarrier::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new CarrierResource($this->service->update($record, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/transportation/carriers/{id}', operationId: 'deleteTransportationCarrier', description: 'Delete a carrier that has no open transport order or trip (soft delete).', summary: 'Delete a carrier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 204, description: 'Carrier deleted'),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $record = TmsCarrier::query()->findOrFail($id);
        $this->authorize('delete', $record);
        $this->service->delete($record);

        return response()->noContent();
    }
}
