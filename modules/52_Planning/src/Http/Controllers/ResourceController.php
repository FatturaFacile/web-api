<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Planning\Http\Controllers\Concerns\InteractsWithPlanning;
use Modules\Planning\Http\Requests\ResourceRateRequest;
use Modules\Planning\Http\Requests\ResourceRequest;
use Modules\Planning\Http\Resources\RateResource;
use Modules\Planning\Http\Resources\ResourceResource;
use Modules\Planning\Models\PlanningItemResourceRate;
use Modules\Planning\Models\PlanningResource;
use Modules\Planning\Services\CapacityService;
use OpenApi\Attributes as OA;

/**
 * Capacity resources and the load rates of the items on them.
 */
final class ResourceController extends Controller
{
    use InteractsWithPlanning;

    public function __construct(private readonly CapacityService $capacity) {}

    #[OA\Get(path: '/api/v1/planning/resources', operationId: 'listPlanningResources', description: 'Capacity resources with the load rates of their items.', summary: 'List capacity resources', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Planning'], responses: [
        new OA\Response(response: 200, description: 'Resources', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PlanningResource'))])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
    ])]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PlanningResource::class);

        return ResourceResource::collection(PlanningResource::query()->with('rates')->where('tenant_id', $this->tenantId())->orderBy('code')->paginate($this->perPage()));
    }

    #[OA\Post(path: '/api/v1/planning/resources', operationId: 'storePlanningResource', description: 'Create a capacity resource. The code is unique in the company.', summary: 'Create a capacity resource', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlanningResourceRequest')), tags: ['Planning'], responses: [
        new OA\Response(response: 201, description: 'Resource created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningResource')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function store(ResourceRequest $request): JsonResponse
    {
        $this->authorize('create', PlanningResource::class);

        return (new ResourceResource($this->capacity->createResource($this->tenantId(), $request->validated())->load('rates')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/planning/resources/{id}', operationId: 'showPlanningResource', description: 'Return a capacity resource with its rates.', summary: 'Show a capacity resource', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 200, description: 'Resource', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningResource')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
    ])]
    public function show(string $id): ResourceResource
    {
        $resource = PlanningResource::query()->with('rates')->findOrFail($id);
        $this->authorize('view', $resource);

        return new ResourceResource($resource);
    }

    #[OA\Put(path: '/api/v1/planning/resources/{id}', operationId: 'updatePlanningResource', description: 'Change a capacity resource.', summary: 'Update a capacity resource', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlanningResourceRequest')), tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 200, description: 'Resource updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningResource')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function update(ResourceRequest $request, string $id): ResourceResource
    {
        $resource = PlanningResource::query()->findOrFail($id);
        $this->authorize('update', $resource);

        return new ResourceResource($this->capacity->updateResource($resource, $request->validated())->load('rates'));
    }

    #[OA\Delete(path: '/api/v1/planning/resources/{id}', operationId: 'deletePlanningResource', description: 'Delete a capacity resource with its rates (soft delete). A resource with constraints in a scenario that left the draft is kept.', summary: 'Delete a capacity resource', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 204, description: 'Resource deleted'),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $resource = PlanningResource::query()->findOrFail($id);
        $this->authorize('delete', $resource);
        $this->capacity->deleteResource($resource);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/planning/resources/{id}/rates', operationId: 'storePlanningResourceRate', description: 'Set the capacity an item consumes on the resource per unit supplied. One rate per item and resource.', summary: 'Add a load rate', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlanningRateRequest')), tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 201, description: 'Rate saved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningRate')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function storeRate(ResourceRateRequest $request, string $id): JsonResponse
    {
        $resource = PlanningResource::query()->findOrFail($id);
        $this->authorize('update', $resource);

        return (new RateResource($this->capacity->saveRate($this->tenantId(), $resource, $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/planning/rates/{id}', operationId: 'updatePlanningResourceRate', description: 'Change a load rate.', summary: 'Update a load rate', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlanningRateRequest')), tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 200, description: 'Rate updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningRate')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function updateRate(ResourceRateRequest $request, string $id): RateResource
    {
        $rate = PlanningItemResourceRate::query()->findOrFail($id);
        $this->authorize('update', $rate);
        $resource = PlanningResource::query()->findOrFail($rate->resource_id);

        return new RateResource($this->capacity->saveRate($this->tenantId(), $resource, $request->validated(), $rate));
    }

    #[OA\Delete(path: '/api/v1/planning/rates/{id}', operationId: 'deletePlanningResourceRate', description: 'Delete a load rate.', summary: 'Delete a load rate', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 204, description: 'Rate deleted'),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
    ])]
    public function destroyRate(string $id): Response
    {
        $rate = PlanningItemResourceRate::query()->findOrFail($id);
        $this->authorize('delete', $rate);
        $rate->delete();

        return response()->noContent();
    }
}
