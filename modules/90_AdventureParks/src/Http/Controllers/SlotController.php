<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\AdventureParks\Http\Controllers\Concerns\ResolvesParkContext;
use Modules\AdventureParks\Http\Requests\SaveSlotRequest;
use Modules\AdventureParks\Http\Resources\SlotTemplateResource;
use Modules\AdventureParks\Models\SlotTemplate;
use Modules\AdventureParks\Services\SlotTemplateService;
use OpenApi\Attributes as OA;

/**
 * Recurring slot templates of the park.
 */
final class SlotController extends Controller
{
    use ApiResponse;
    use ResolvesParkContext;

    public function __construct(private readonly SlotTemplateService $slots) {}

    #[OA\Get(path: '/api/v1/adventure-parks/slots', operationId: 'adventureParksSlotsList', summary: 'List the slot templates', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], responses: [new OA\Response(response: 200, description: 'Slot templates')])]
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', SlotTemplate::class);

        return $this->successResponse(SlotTemplateResource::collection($this->slots->all()));
    }

    #[OA\Post(path: '/api/v1/adventure-parks/slots', operationId: 'adventureParksSlotsCreate', summary: 'Create a slot template', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], responses: [new OA\Response(response: 201, description: 'Slot template created')])]
    public function store(SaveSlotRequest $request): JsonResponse
    {
        return $this->successResponse(new SlotTemplateResource($this->slots->create($request->validated(), $this->userId($request))), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/adventure-parks/slots/{id}', operationId: 'adventureParksSlotsUpdate', summary: 'Update a slot template', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Slot template updated')])]
    public function update(SaveSlotRequest $request, string $id): JsonResponse
    {
        $slot = SlotTemplate::query()->findOrFail($id);

        return $this->successResponse(new SlotTemplateResource($this->slots->update($slot, $request->validated(), $this->userId($request))), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/adventure-parks/slots/{id}', operationId: 'adventureParksSlotsDelete', summary: 'Delete a slot template without open bookings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Slot template deleted')])]
    public function destroy(string $id): JsonResponse
    {
        Gate::authorize('manage', SlotTemplate::class);
        $this->slots->delete(SlotTemplate::query()->findOrFail($id));

        return $this->successResponse(null, $this->message('deleted'));
    }
}
