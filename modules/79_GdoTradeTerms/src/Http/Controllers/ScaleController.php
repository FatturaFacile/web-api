<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\GdoTradeTerms\Application\Commands\SaveScaleCommand;
use Modules\GdoTradeTerms\Application\Commands\SaveScaleHandler;
use Modules\GdoTradeTerms\Application\Queries\SimulateScaleHandler;
use Modules\GdoTradeTerms\Application\Queries\SimulateScaleQuery;
use Modules\GdoTradeTerms\Http\Controllers\Concerns\InteractsWithGdo;
use Modules\GdoTradeTerms\Http\Requests\SaveScaleRequest;
use Modules\GdoTradeTerms\Http\Requests\SimulateScaleRequest;
use Modules\GdoTradeTerms\Http\Resources\ScaleResource;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Models\GdoScale;
use Modules\GdoTradeTerms\Services\ScaleService;
use OpenApi\Attributes as OA;

/**
 * Tiered year-end rebate scales (PFA) of a contract and the bracket simulator.
 */
final class ScaleController extends Controller
{
    use ApiResponse;
    use InteractsWithGdo;

    #[OA\Post(path: '/api/v1/gdo-trade-terms/contracts/{id}/scales', operationId: 'gdoTradeTermsCreateScale', summary: 'Add a rebate scale with its brackets to a contract', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Scale created')])]
    public function store(SaveScaleRequest $request, string $id, SaveScaleHandler $handler): JsonResponse
    {
        Gate::authorize('update', GdoContract::query()->findOrFail($id));

        $scale = $handler->handle(new SaveScaleCommand($id, $request->validated(), $this->userId()));

        return $this->successResponse(new ScaleResource($scale->load('tiers')), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/gdo-trade-terms/contracts/{id}/scales/{scaleId}', operationId: 'gdoTradeTermsUpdateScale', summary: 'Update a rebate scale and its brackets', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'scaleId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Scale updated')])]
    public function update(SaveScaleRequest $request, string $id, string $scaleId, SaveScaleHandler $handler): JsonResponse
    {
        Gate::authorize('update', GdoContract::query()->findOrFail($id));

        $scale = $handler->handle(new SaveScaleCommand($id, $request->validated(), $this->userId(), $scaleId));

        return $this->successResponse(new ScaleResource($scale->load('tiers')), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/gdo-trade-terms/contracts/{id}/scales/{scaleId}', operationId: 'gdoTradeTermsDeleteScale', summary: 'Delete a rebate scale that accrued nothing', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'scaleId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Scale deleted')])]
    public function destroy(string $id, string $scaleId, ScaleService $scales): JsonResponse
    {
        $contract = GdoContract::query()->findOrFail($id);
        Gate::authorize('update', $contract);
        $scales->delete(GdoScale::query()->where('contract_id', $contract->id)->findOrFail($scaleId), $this->userId());

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Post(path: '/api/v1/gdo-trade-terms/scale-simulations', operationId: 'gdoTradeTermsSimulateScale', summary: 'Simulate the rebate of a list of volumes under retroactive and progressive brackets', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], responses: [new OA\Response(response: 200, description: 'Simulation')])]
    public function simulate(SimulateScaleRequest $request, SimulateScaleHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', GdoContract::class);

        $data = $request->validated();

        return $this->successResponse($handler->handle(new SimulateScaleQuery(
            tierMode: (string) $data['tier_mode'],
            volumeMode: (string) ($data['volume_mode'] ?? 'total'),
            baseline: (string) ($data['baseline_value'] ?? '0'),
            tiers: array_map(static fn (array $tier): array => ['threshold' => (string) $tier['threshold'], 'rate_percent' => (string) $tier['rate_percent'], 'flat_amount' => isset($tier['flat_amount']) ? (string) $tier['flat_amount'] : null], $data['tiers']),
            volumes: array_map(static fn (mixed $volume): string => (string) $volume, $data['volumes']),
        )));
    }
}
