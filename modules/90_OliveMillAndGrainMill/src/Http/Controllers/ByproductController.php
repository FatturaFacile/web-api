<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\OliveMillAndGrainMill\Application\Commands\RecordByproductMovementCommand;
use Modules\OliveMillAndGrainMill\Application\Handlers\RecordByproductMovementHandler;
use Modules\OliveMillAndGrainMill\Application\Queries\MillQueries;
use Modules\OliveMillAndGrainMill\Http\Controllers\Concerns\InteractsWithOliveMill;
use Modules\OliveMillAndGrainMill\Http\Requests\RecordByproductRequest;
use Modules\OliveMillAndGrainMill\Http\Resources\ByproductResource;
use Modules\OliveMillAndGrainMill\Models\ByproductMovement;
use OpenApi\Attributes as OA;

/**
 * By-product traceability register: pomace, olive pits, vegetation water and bran.
 */
final class ByproductController extends Controller
{
    use ApiResponse;
    use InteractsWithOliveMill;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/olive-mill/mill-byproducts', operationId: 'oliveMillByproductsIndex', summary: 'By-product movements (filter by type, parcel, period)', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'By-products')])]
    public function index(MillQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', ByproductMovement::class);

        return $this->paged($queries->byproducts($this->filters(), $this->perPage()), ByproductResource::class);
    }

    #[OA\Post(path: '/api/v1/olive-mill/mill-byproducts', operationId: 'oliveMillByproductsStore', summary: 'Record a by-product dispatch, use or land spreading', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 201, description: 'By-product movement')])]
    public function store(RecordByproductRequest $request, RecordByproductMovementHandler $handler): JsonResponse
    {
        Gate::authorize('create', ByproductMovement::class);

        $movement = $handler->handle(RecordByproductMovementCommand::fromArray($request->validated(), $this->userId()));

        return $this->createdResponse(new ByproductResource($movement), $this->message('byproduct_recorded'));
    }
}
