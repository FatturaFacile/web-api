<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\ConstructionTrades\Http\Requests\SaveWorkCycleRequest;
use Modules\ConstructionTrades\Http\Resources\TradesResource;
use Modules\ConstructionTrades\Models\WorkCycle;
use Modules\ConstructionTrades\Models\WorkCycleStep;
use OpenApi\Attributes as OA;

/**
 * Personalised work cycles: ordered steps added to a bid as one composite position.
 */
final class WorkCycleController extends Controller
{
    /**
     * Return the work cycles of the company and the generic ones.
     */
    #[OA\Get(path: '/api/v1/construction-trades/work-cycles', operationId: 'listTradesWorkCycles', description: 'Return the work cycles of the company and the generic ones.', summary: 'List work cycles', security: [['bearerAuth' => []]], tags: ['Construction Trades'], responses: [
        new OA\Response(response: 200, description: 'Work cycles', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordCollection')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WorkCycle::class);

        return TradesResource::collection(WorkCycle::query()->with('steps')->orderBy('name')->paginate(max(1, min($request->integer('per_page', 25), 100))));
    }

    /**
     * Create a work cycle with its ordered steps.
     */
    #[OA\Post(path: '/api/v1/construction-trades/work-cycles', operationId: 'storeTradesWorkCycle', description: 'Create a work cycle with its ordered steps.', summary: 'Create work cycle', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TradesWorkCycleRequest')), tags: ['Construction Trades'], responses: [
        new OA\Response(response: 201, description: 'Work cycle created', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function store(SaveWorkCycleRequest $request): JsonResponse
    {
        $this->authorize('create', WorkCycle::class);
        $data = $request->validated();

        $cycle = DB::transaction(function () use ($data): WorkCycle {
            $cycle = new WorkCycle;
            $cycle->fill(collect($data)->except('steps')->all());
            $cycle->save();

            foreach (array_values($data['steps']) as $index => $step) {
                $model = new WorkCycleStep;
                $model->fill([
                    ...$step,
                    'work_cycle_id' => $cycle->id,
                    'sort_order' => $index + 1,
                    'quantity_factor' => $step['quantity_factor'] ?? '1',
                    'labour_minutes' => $step['labour_minutes'] ?? '0',
                    'material_cost' => $step['material_cost'] ?? '0',
                    'machine_cost' => $step['machine_cost'] ?? '0',
                ]);
                $model->save();
            }

            return $cycle;
        });

        return (new TradesResource($cycle->load('steps')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Return a work cycle with its steps.
     */
    #[OA\Get(path: '/api/v1/construction-trades/work-cycles/{id}', operationId: 'showTradesWorkCycle', description: 'Return a work cycle with its steps.', summary: 'Show work cycle', security: [['bearerAuth' => []]], tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Work cycle', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
    ])]
    public function show(string $id): TradesResource
    {
        $cycle = WorkCycle::query()->findOrFail($id);
        $this->authorize('view', $cycle);

        return new TradesResource($cycle->load('steps'));
    }
}
