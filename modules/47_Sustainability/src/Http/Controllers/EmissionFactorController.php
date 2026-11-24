<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Sustainability\Http\Controllers\Concerns\InteractsWithSustainability;
use Modules\Sustainability\Http\Requests\EmissionFactorRequest;
use Modules\Sustainability\Http\Requests\NewFactorVersionRequest;
use Modules\Sustainability\Http\Resources\EmissionFactorResource;
use Modules\Sustainability\Models\EsgEmissionFactor;
use Modules\Sustainability\Services\EmissionFactorService;
use Modules\Sustainability\Support\ActivityCatalog;
use OpenApi\Attributes as OA;

/**
 * The emission factor library: factors per activity type, unit, source, year and region, with versions.
 */
final class EmissionFactorController extends Controller
{
    use InteractsWithSustainability;

    public function __construct(private readonly EmissionFactorService $factors) {}

    #[OA\Get(path: '/api/v1/sustainability/factors', operationId: 'listEsgEmissionFactors', description: 'Paginated emission factors. Filters: activity_type, region, is_active (0|1), search (code or name), latest_only=1 (only the newest version of each code).', summary: 'List emission factors', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], responses: [
        new OA\Response(response: 200, description: 'Emission factors', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SustainabilityFactor'))])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', EsgEmissionFactor::class);
        $filters = $request->validate([
            'activity_type' => ['nullable', Rule::in(ActivityCatalog::types())],
            'region' => ['nullable', Rule::in(ActivityCatalog::Regions)],
            'is_active' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'latest_only' => ['nullable', 'boolean'],
        ]);

        $query = EsgEmissionFactor::query()
            ->where('tenant_id', $this->tenantId())
            ->when(isset($filters['activity_type']), static fn ($query) => $query->where('activity_type', $filters['activity_type']))
            ->when(isset($filters['region']), static fn ($query) => $query->where('region', $filters['region']))
            ->when(isset($filters['is_active']), static fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->when(isset($filters['search']), static fn ($query) => $query->where(static fn ($inner) => $inner->where('code', 'like', '%'.$filters['search'].'%')->orWhere('name', 'like', '%'.$filters['search'].'%')))
            ->when($request->boolean('latest_only'), static fn ($query) => $query->whereRaw('version = (select max(f2.version) from esg_emission_factors f2 where f2.tenant_id = esg_emission_factors.tenant_id and f2.code = esg_emission_factors.code and f2.deleted_at is null)'))
            ->orderBy('activity_type')
            ->orderBy('code')
            ->orderByDesc('version');

        return EmissionFactorResource::collection($query->paginate($this->perPage()));
    }

    #[OA\Post(path: '/api/v1/sustainability/factors', operationId: 'storeEsgEmissionFactor', description: 'Create an emission factor. The scope and the category follow from the activity type; the unit must be one the type is measured in. The code is unique in the company: later versions of a factor are created with new-version.', summary: 'Create an emission factor', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilityFactorRequest')), tags: ['Sustainability'], responses: [
        new OA\Response(response: 201, description: 'Factor created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityFactor')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function store(EmissionFactorRequest $request): JsonResponse
    {
        $this->authorize('create', EsgEmissionFactor::class);

        return (new EmissionFactorResource($this->factors->create($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/sustainability/factors/{id}', operationId: 'showEsgEmissionFactor', description: 'Return an emission factor.', summary: 'Show an emission factor', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Factor', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityFactor')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
    ])]
    public function show(string $id): EmissionFactorResource
    {
        $factor = EsgEmissionFactor::query()->findOrFail($id);
        $this->authorize('view', $factor);

        return new EmissionFactorResource($factor);
    }

    #[OA\Put(path: '/api/v1/sustainability/factors/{id}', operationId: 'updateEsgEmissionFactor', description: 'Update an emission factor. Once activity records use it, the value, the unit and the activity type are frozen: create a new version instead.', summary: 'Update an emission factor', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilityFactorRequest')), tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Factor updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityFactor')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function update(EmissionFactorRequest $request, string $id): EmissionFactorResource
    {
        $factor = EsgEmissionFactor::query()->findOrFail($id);
        $this->authorize('update', $factor);

        return new EmissionFactorResource($this->factors->update($factor, $request->validated()));
    }

    #[OA\Post(path: '/api/v1/sustainability/factors/{id}/new-version', operationId: 'versionEsgEmissionFactor', description: 'Create the next version of the factor with a new value and source. The previous version is deactivated for new records and stays frozen on the records that used it.', summary: 'Create a new factor version', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilityFactorVersionRequest')), tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 201, description: 'New version', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityFactor')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function newVersion(NewFactorVersionRequest $request, string $id): JsonResponse
    {
        $factor = EsgEmissionFactor::query()->findOrFail($id);
        $this->authorize('update', $factor);

        return (new EmissionFactorResource($this->factors->newVersion($factor, $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Delete(path: '/api/v1/sustainability/factors/{id}', operationId: 'deleteEsgEmissionFactor', description: 'Delete a factor no activity record uses; a factor in use is deactivated instead (200 with the factor, 204 when deleted).', summary: 'Delete or deactivate an emission factor', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Factor in use, deactivated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityFactor')])),
        new OA\Response(response: 204, description: 'Factor deleted'),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
    ])]
    public function destroy(string $id): JsonResponse|Response
    {
        $factor = EsgEmissionFactor::query()->findOrFail($id);
        $this->authorize('delete', $factor);

        return $this->factors->remove($factor) ? response()->noContent() : (new EmissionFactorResource($factor->refresh()))->response();
    }

    #[OA\Post(path: '/api/v1/sustainability/factors/install-examples', operationId: 'installEsgExampleFactors', description: 'Install the example factor library (IT, CH, EU) for the company, idempotent by code. The values are indicative examples marked is_example: replace them with the official sources of the company.', summary: 'Install the example factors', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], responses: [
        new OA\Response(response: 200, description: 'Number of factors created', content: new OA\JsonContent(properties: [new OA\Property(property: 'created', type: 'integer')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
    ])]
    public function installExamples(): JsonResponse
    {
        $this->authorize('create', EsgEmissionFactor::class);

        return response()->json(['created' => $this->factors->installExamples($this->tenantId())]);
    }
}
