<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\OliveMillAndGrainMill\Application\Commands\SettleMillingCycleCommand;
use Modules\OliveMillAndGrainMill\Application\Handlers\MillingSettlementHandler;
use Modules\OliveMillAndGrainMill\Application\Queries\MillQueries;
use Modules\OliveMillAndGrainMill\Http\Controllers\Concerns\InteractsWithOliveMill;
use Modules\OliveMillAndGrainMill\Http\Requests\SettleCycleRequest;
use Modules\OliveMillAndGrainMill\Http\Resources\SettlementResource;
use Modules\OliveMillAndGrainMill\Models\MillingCycle;
use Modules\OliveMillAndGrainMill\Models\MillingSettlement;
use OpenApi\Attributes as OA;

/**
 * Settlement of the milling service: preview of the cash / molenda / hybrid split and the one-step settlement.
 */
final class SettlementController extends Controller
{
    use ApiResponse;
    use InteractsWithOliveMill;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/olive-mill/settlements', operationId: 'oliveMillSettlementsIndex', summary: 'Settled milling services', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Settlements')])]
    public function index(MillQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', MillingSettlement::class);

        return $this->paged($queries->settlements($this->filters(), $this->perPage()), SettlementResource::class);
    }

    #[OA\Post(path: '/api/v1/olive-mill/milling-cycles/{id}/preview-settlement', operationId: 'oliveMillSettlementPreview', summary: 'Calculate the settlement (service invoice, retained oil, purchase document, cash balance) without writing', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Preview')])]
    public function preview(SettleCycleRequest $request, string $id, MillingSettlementHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', MillingCycle::class);

        return $this->successResponse($handler->preview(SettleMillingCycleCommand::fromArray(['cycle_id' => $id] + $request->validated(), $this->userId())), $this->message('settlement_previewed'));
    }

    #[OA\Post(path: '/api/v1/olive-mill/milling-cycles/{id}/settle', operationId: 'oliveMillSettlementSettle', summary: 'Settle the cycle: service invoice, purchase document, SIAN load and compensation in one transaction', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 201, description: 'Settlement')])]
    public function settle(SettleCycleRequest $request, string $id, MillingSettlementHandler $handler): JsonResponse
    {
        Gate::authorize('update', MillingCycle::query()->findOrFail($id));

        $settlement = $handler->handle(SettleMillingCycleCommand::fromArray(['cycle_id' => $id] + $request->validated(), $this->userId()));

        return $this->createdResponse(new SettlementResource($settlement), $this->message('settled'));
    }
}
