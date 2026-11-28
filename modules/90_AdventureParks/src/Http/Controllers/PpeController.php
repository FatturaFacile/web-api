<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\AdventureParks\Http\Controllers\Concerns\ResolvesParkContext;
use Modules\AdventureParks\Http\Requests\BatchPpeRequest;
use Modules\AdventureParks\Http\Requests\InspectionRequest;
use Modules\AdventureParks\Http\Requests\PpeIndexRequest;
use Modules\AdventureParks\Http\Requests\SavePpeRequest;
use Modules\AdventureParks\Http\Resources\InspectionResource;
use Modules\AdventureParks\Http\Resources\PpeResource;
use Modules\AdventureParks\Models\PpeAsset;
use Modules\AdventureParks\Services\PpeRegistryService;
use OpenApi\Attributes as OA;

/**
 * PPE register (UNI EN 365): items, batches, inspections and expiry alerts.
 */
final class PpeController extends Controller
{
    use ApiResponse;
    use ResolvesParkContext;

    public function __construct(private readonly PpeRegistryService $registry) {}

    #[OA\Get(path: '/api/v1/adventure-parks/ppe', operationId: 'adventureParksPpeList', summary: 'List the PPE items', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], responses: [new OA\Response(response: 200, description: 'PPE items')])]
    public function index(PpeIndexRequest $request): JsonResponse
    {
        return $this->successResponse(PpeResource::collection($this->registry->list($request->validated())));
    }

    #[OA\Post(path: '/api/v1/adventure-parks/ppe', operationId: 'adventureParksPpeCreate', summary: 'Register a PPE item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], responses: [new OA\Response(response: 201, description: 'PPE registered')])]
    public function store(SavePpeRequest $request): JsonResponse
    {
        return $this->successResponse(new PpeResource($this->registry->create($request->validated(), $this->userId($request))), $this->message('saved'), 201);
    }

    #[OA\Post(path: '/api/v1/adventure-parks/ppe/batch', operationId: 'adventureParksPpeBatch', summary: 'Register a batch of identical PPE items with sequential serial codes', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], responses: [new OA\Response(response: 201, description: 'PPE registered')])]
    public function batch(BatchPpeRequest $request): JsonResponse
    {
        return $this->successResponse(PpeResource::collection($this->registry->createBatch($request->validated(), $this->userId($request))), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/adventure-parks/ppe/alerts', operationId: 'adventureParksPpeAlerts', summary: 'Lifespan and EN 365 inspection alerts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], responses: [new OA\Response(response: 200, description: 'Alerts')])]
    public function alerts(): JsonResponse
    {
        Gate::authorize('viewAny', PpeAsset::class);

        return $this->successResponse($this->registry->alerts(CarbonImmutable::today()));
    }

    #[OA\Get(path: '/api/v1/adventure-parks/ppe/{id}', operationId: 'adventureParksPpeShow', summary: 'Show a PPE item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'PPE item')])]
    public function show(string $id): JsonResponse
    {
        Gate::authorize('viewAny', PpeAsset::class);

        return $this->successResponse(new PpeResource(PpeAsset::query()->findOrFail($id)));
    }

    #[OA\Put(path: '/api/v1/adventure-parks/ppe/{id}', operationId: 'adventureParksPpeUpdate', summary: 'Update a PPE item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'PPE item updated')])]
    public function update(SavePpeRequest $request, string $id): JsonResponse
    {
        $asset = PpeAsset::query()->findOrFail($id);

        return $this->successResponse(new PpeResource($this->registry->update($asset, $request->validated(), $this->userId($request))), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/adventure-parks/ppe/{id}', operationId: 'adventureParksPpeDelete', summary: 'Delete a PPE item that is not out with a visitor', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'PPE item deleted')])]
    public function destroy(string $id): JsonResponse
    {
        Gate::authorize('manage', PpeAsset::class);
        $this->registry->delete(PpeAsset::query()->findOrFail($id));

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Get(path: '/api/v1/adventure-parks/ppe/{id}/inspections', operationId: 'adventureParksPpeInspections', summary: 'Inspection history of a PPE item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Inspections')])]
    public function inspections(string $id): JsonResponse
    {
        Gate::authorize('viewAny', PpeAsset::class);
        $asset = PpeAsset::query()->findOrFail($id);

        return $this->successResponse(InspectionResource::collection($asset->inspections()->orderByDesc('inspected_on')->orderByDesc('created_at')->get()));
    }

    #[OA\Post(path: '/api/v1/adventure-parks/ppe/{id}/inspections', operationId: 'adventureParksPpeInspectionCreate', summary: 'Record an inspection (a failed one sends the item to maintenance, a passed EN 365 one unlocks it)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Inspection recorded')])]
    public function storeInspection(InspectionRequest $request, string $id): JsonResponse
    {
        $asset = PpeAsset::query()->findOrFail($id);

        return $this->successResponse(new InspectionResource($this->registry->inspect($asset, $request->validated(), $this->userId($request))), $this->message('inspection_recorded'), 201);
    }
}
