<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\AdventureParks\Http\Controllers\Concerns\ResolvesParkContext;
use Modules\AdventureParks\Http\Requests\SaveWorkshopRequest;
use Modules\AdventureParks\Http\Resources\WorkshopResource;
use Modules\AdventureParks\Models\EducationalWorkshop;
use Modules\AdventureParks\Services\WorkshopCatalogue;
use OpenApi\Attributes as OA;

/**
 * Educational farm workshop catalogue.
 */
final class WorkshopController extends Controller
{
    use ApiResponse;
    use ResolvesParkContext;

    public function __construct(private readonly WorkshopCatalogue $catalogue) {}

    #[OA\Get(path: '/api/v1/adventure-parks/workshops', operationId: 'adventureParksWorkshopsList', summary: 'List the workshops of the educational farm', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], responses: [new OA\Response(response: 200, description: 'Workshops')])]
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', EducationalWorkshop::class);

        return $this->successResponse(WorkshopResource::collection($this->catalogue->all()));
    }

    #[OA\Post(path: '/api/v1/adventure-parks/workshops', operationId: 'adventureParksWorkshopsCreate', summary: 'Create a workshop', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], responses: [new OA\Response(response: 201, description: 'Workshop created')])]
    public function store(SaveWorkshopRequest $request): JsonResponse
    {
        return $this->successResponse(new WorkshopResource($this->catalogue->create($request->validated(), $this->userId($request))), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/adventure-parks/workshops/{id}', operationId: 'adventureParksWorkshopsUpdate', summary: 'Update a workshop (a standard workshop gets the company own copy)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Workshop updated')])]
    public function update(SaveWorkshopRequest $request, string $id): JsonResponse
    {
        $workshop = EducationalWorkshop::query()->findOrFail($id);

        return $this->successResponse(new WorkshopResource($this->catalogue->update($workshop, $request->validated(), $this->userId($request))), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/adventure-parks/workshops/{id}', operationId: 'adventureParksWorkshopsDelete', summary: 'Delete a workshop of the company that no booking uses', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Workshop deleted')])]
    public function destroy(string $id): JsonResponse
    {
        Gate::authorize('manage', EducationalWorkshop::class);
        $this->catalogue->delete(EducationalWorkshop::query()->findOrFail($id));

        return $this->successResponse(null, $this->message('deleted'));
    }
}
