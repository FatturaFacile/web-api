<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Beekeeping\Http\Controllers\Concerns\ResolvesBeekeepingContext;
use Modules\Beekeeping\Http\Requests\MovementStatusRequest;
use Modules\Beekeeping\Http\Requests\SaveMovementRequest;
use Modules\Beekeeping\Http\Resources\MovementResource;
use Modules\Beekeeping\Models\NomadismMovement;
use Modules\Beekeeping\Services\MovementService;
use OpenApi\Attributes as OA;

/**
 * Nomadism (transhumance) and sale / purchase movements.
 */
final class MovementController extends Controller
{
    use ApiResponse;
    use ResolvesBeekeepingContext;

    public function __construct(private readonly MovementService $movements) {}

    #[OA\Get(path: '/api/v1/beekeeping/movements', operationId: 'beekeepingMovementsList', summary: 'List the movements', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 200, description: 'Movements')])]
    public function index(): JsonResponse
    {
        $this->authorizeRead();

        return $this->successResponse(MovementResource::collection(NomadismMovement::query()->orderByDesc('departure_date')->get()));
    }

    #[OA\Post(path: '/api/v1/beekeeping/movements', operationId: 'beekeepingMovementsCreate', summary: 'Plan a movement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 201, description: 'Movement planned')])]
    public function store(SaveMovementRequest $request): JsonResponse
    {
        $movement = $this->movements->create($request->validated() + ['created_by' => $this->userId($request)]);

        return $this->successResponse(new MovementResource($movement), $this->message('movement_created'), 201);
    }

    #[OA\Get(path: '/api/v1/beekeeping/movements/{id}', operationId: 'beekeepingMovementsShow', summary: 'Show a movement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Movement')])]
    public function show(string $id): JsonResponse
    {
        $this->authorizeRead();

        return $this->successResponse(new MovementResource(NomadismMovement::query()->findOrFail($id)));
    }

    #[OA\Post(path: '/api/v1/beekeeping/movements/{id}/status', operationId: 'beekeepingMovementsStatus', summary: 'Change the status of a movement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Movement updated')])]
    public function status(MovementStatusRequest $request, string $id): JsonResponse
    {
        $movement = $this->movements->transition(NomadismMovement::query()->findOrFail($id), (string) $request->validated('status'), $request->validated('arrival_date'));

        return $this->successResponse(new MovementResource($movement), $this->message('saved'));
    }
}
