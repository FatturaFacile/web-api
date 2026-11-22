<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ArtisanalGelato\Application\Services\GelatoGanacheAndOrderService;
use Modules\ArtisanalGelato\Application\Services\GelatoLotService;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoPasteurizationChurnLot;
use Modules\ArtisanalGelato\Presentation\Http\Controllers\Concerns\MapsGelatoErrors;
use Modules\ArtisanalGelato\Presentation\Http\Requests\GelatoLotRequest;
use Modules\ArtisanalGelato\Presentation\Http\Resources\GelatoLotResource;
use OpenApi\Attributes as OA;

/**
 * Pasteurization and churning lots, the HACCP thermal log, the genealogy and the scale readings of the weighing station.
 */
final class GelatoLotController extends Controller
{
    use ApiResponse;
    use MapsGelatoErrors;

    #[OA\Get(path: '/api/v1/gelato/lots', operationId: 'listGelatoLots', summary: 'Pasteurization and churning lots', description: 'Optionally filtered by kind (base, flavor) and status.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], responses: [new OA\Response(response: 200, description: 'The lots, newest first')])]
    public function index(Request $request): JsonResponse
    {
        $query = GelatoPasteurizationChurnLot::query()->latest('started_at');

        foreach (['kind', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, (string) $request->query($filter));
            }
        }

        return $this->successResponse(GelatoLotResource::collection($query->limit(200)->get()));
    }

    #[OA\Post(path: '/api/v1/gelato/lots', operationId: 'startGelatoLot', summary: 'Start a base or flavor lot', description: 'A flavor lot may draw grams from the completed, HACCP-passed parent base lot (two-stage method) and records its raw material lots.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], responses: [new OA\Response(response: 201, description: 'The lot'), new OA\Response(response: 422, description: 'Invalid lot')])]
    public function store(GelatoLotRequest $request, GelatoLotService $service): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse(new GelatoLotResource($service->start($data, $data['consumptions'] ?? [], $this->userId())), 'Lot started', 201));
    }

    #[OA\Post(path: '/api/v1/gelato/lots/{id}/haccp-readings', operationId: 'recordGelatoHaccpReadings', summary: 'Add HACCP thermal readings', description: 'The curve (+85 C, +4 C, -18 C) is evaluated again after every batch of readings.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The lot with its verdict')])]
    public function haccp(string $id, GelatoLotRequest $request, GelatoLotService $service): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(new GelatoLotResource($service->recordHaccp($id, $request->validated('readings'))), 'Readings recorded'));
    }

    #[OA\Post(path: '/api/v1/gelato/lots/{id}/complete', operationId: 'completeGelatoLot', summary: 'Complete a lot', description: 'Needs a passed HACCP curve; records the yield and computes the overrun from the volumes.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The completed lot'), new OA\Response(response: 422, description: 'HACCP not passed')])]
    public function complete(string $id, GelatoLotRequest $request, GelatoLotService $service): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(new GelatoLotResource($service->complete($id, $request->validated())), 'Lot completed'));
    }

    #[OA\Get(path: '/api/v1/gelato/lots/{id}/genealogy', operationId: 'getGelatoLotGenealogy', summary: 'Lot genealogy', description: 'Raw material lots, parent base lots and the flavor lots drawn from the lot.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The genealogy')])]
    public function genealogy(string $id, GelatoLotService $service): JsonResponse
    {
        return $this->successResponse($service->genealogy($id));
    }

    #[OA\Post(path: '/api/v1/gelato/scale-readings', operationId: 'recordGelatoScaleReading', summary: 'Record a scale reading', description: 'The weight typed by the operator, or the stable weight of the connected scale when grams is omitted.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], responses: [new OA\Response(response: 201, description: 'The reading'), new OA\Response(response: 422, description: 'No stable reading')])]
    public function scale(GelatoLotRequest $request, GelatoGanacheAndOrderService $service): JsonResponse
    {
        return $this->guarded(function () use ($request, $service): JsonResponse {
            $reading = $service->recordScaleReading($request->validated());

            return $this->successResponse($reading->only(['id', 'lot_id', 'ingredient_id', 'grams', 'source', 'device', 'read_at']), 'Reading recorded', 201);
        });
    }
}
