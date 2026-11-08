<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Inventory\Http\Requests\CycleCountPlanRequest;
use Modules\Inventory\Http\Requests\WmsQueryRequest;
use Modules\Inventory\Http\Resources\InventoryCountSessionResource;
use Modules\Inventory\Models\CycleCountPlan;
use Modules\Inventory\Services\AbcClassificationService;
use Modules\Inventory\Services\CycleCountPlanService;
use OpenApi\Attributes as OA;

/**
 * Cycle counting: ABC classification of the items and the recurring count plans built on it. Classifying and
 * changing plans are manager decisions; running a plan opens an ordinary count session.
 */
#[OA\Tag(name: 'Inventory Cycle Counting', description: 'ABC classification, cycle count plans and their runs')]
final class CycleCountController extends Controller
{
    #[OA\Post(
        path: '/api/v1/inventory/abc-classification/run',
        operationId: 'wmsRunAbcClassification',
        summary: 'Classify items A/B/C',
        description: 'Manager decision (owner/admin). Ranks the active items by the value of their consumption (movements out of stock, excluding adjustments, scrap and shortfall settlements) over the last period_days days and stores the class on each item: A up to a_share percent of the cumulative value, B up to b_share, the rest C (items without consumption are C). Defaults 80/95 are the conventional Pareto split.',
        tags: ['Inventory Cycle Counting'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['period_days'], properties: [
            new OA\Property(property: 'period_days', type: 'integer', example: 365),
            new OA\Property(property: 'a_share', type: 'number', example: 80),
            new OA\Property(property: 'b_share', type: 'number', example: 95),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Classes per item', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsAbcRow'))])),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function classify(WmsQueryRequest $request, AbcClassificationService $abc): JsonResponse
    {
        $this->authorize('inventory.manage');

        return response()->json(['data' => $abc->classify(
            (int) $request->input('period_days'),
            (string) $request->input('a_share', '80'),
            (string) $request->input('b_share', '95'),
        )]);
    }

    #[OA\Get(
        path: '/api/v1/inventory/cycle-count-plans',
        operationId: 'wmsListCycleCountPlans',
        summary: 'List cycle count plans',
        description: 'Cycle count plans, optionally of one warehouse, with their next run date.',
        tags: ['Inventory Cycle Counting'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsWarehouseFilter')],
        responses: [
            new OA\Response(response: 200, description: 'Plans', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsCycleCountPlan'))])),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $plans = CycleCountPlan::query()
            ->when($request->filled('warehouse_id'), static fn ($query) => $query->where('warehouse_id', (string) $request->query('warehouse_id')))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $plans->map(static fn (CycleCountPlan $plan): array => self::plan($plan))->all()]);
    }

    #[OA\Post(
        path: '/api/v1/inventory/cycle-count-plans',
        operationId: 'wmsStoreCycleCountPlan',
        summary: 'Create a cycle count plan',
        description: 'Manager decision (owner/admin). Counts the items of an ABC class (or all items) of a warehouse every interval_days days; with freezes_stock its sessions block movements in the counted warehouse until approved or cancelled. The first run is due at once unless next_run_on is given.',
        tags: ['Inventory Cycle Counting'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WmsCycleCountPlanInput')),
        responses: [
            new OA\Response(response: 201, description: 'Plan created', content: new OA\JsonContent(ref: '#/components/schemas/WmsCycleCountPlan')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function store(CycleCountPlanRequest $request): JsonResponse
    {
        $this->authorize('inventory.manage');

        $plan = CycleCountPlan::create($request->validated() + ['created_by' => (string) auth()->id()]);

        return response()->json(self::plan($plan->fresh() ?? $plan), Response::HTTP_CREATED);
    }

    #[OA\Put(
        path: '/api/v1/inventory/cycle-count-plans/{id}',
        operationId: 'wmsUpdateCycleCountPlan',
        summary: 'Update a cycle count plan',
        description: 'Manager decision (owner/admin). Changes the class, interval, freeze, activity or next run date of the plan.',
        tags: ['Inventory Cycle Counting'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WmsCycleCountPlanInput')),
        responses: [
            new OA\Response(response: 200, description: 'Plan updated', content: new OA\JsonContent(ref: '#/components/schemas/WmsCycleCountPlan')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function update(string $id, CycleCountPlanRequest $request): JsonResponse
    {
        $plan = CycleCountPlan::query()->findOrFail($id);
        $this->authorize('inventory.manage');

        $plan->update($request->validated());

        return response()->json(self::plan($plan->fresh() ?? $plan));
    }

    #[OA\Delete(
        path: '/api/v1/inventory/cycle-count-plans/{id}',
        operationId: 'wmsDeleteCycleCountPlan',
        summary: 'Delete a cycle count plan',
        description: 'Manager decision (owner/admin). The sessions it already opened are kept.',
        tags: ['Inventory Cycle Counting'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 204, description: 'Plan deleted'),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        ]
    )]
    public function destroy(string $id): Response
    {
        $plan = CycleCountPlan::query()->findOrFail($id);
        $this->authorize('inventory.manage');

        $plan->delete();

        return response()->noContent();
    }

    #[OA\Post(
        path: '/api/v1/inventory/cycle-count-plans/{id}/run',
        operationId: 'wmsRunCycleCountPlan',
        summary: 'Run a cycle count plan now',
        description: 'Opens a count session for the items of the plan that have stock in its warehouse (freezing them if the plan says so) and schedules the next run interval_days later. 422 when the plan is inactive, its previous session is still open or no item is in scope.',
        tags: ['Inventory Cycle Counting'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 201, description: 'Count session opened', content: new OA\JsonContent(ref: '#/components/schemas/WmsCountSession')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function run(string $id, CycleCountPlanService $plans): JsonResponse
    {
        $session = $plans->run(CycleCountPlan::query()->findOrFail($id), (string) auth()->id());

        return response()->json(new InventoryCountSessionResource($session->load(['warehouse', 'zone', 'lines.item', 'lines.bin'])), Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/v1/inventory/cycle-count-plans/run-due',
        operationId: 'wmsRunDueCycleCountPlans',
        summary: 'Run every due cycle count plan',
        description: 'Runs the active plans of the company whose next run date is today or earlier (or never run); plans that cannot run (session still open, nothing in scope) are skipped.',
        tags: ['Inventory Cycle Counting'],
        responses: [
            new OA\Response(response: 200, description: 'Number of sessions opened', content: new OA\JsonContent(properties: [new OA\Property(property: 'opened', type: 'integer', example: 2)])),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        ]
    )]
    public function runDue(CycleCountPlanService $plans): JsonResponse
    {
        return response()->json(['opened' => $plans->runDue((string) auth()->id())]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function plan(CycleCountPlan $plan): array
    {
        return [
            'id' => (string) $plan->id,
            'warehouse_id' => (string) $plan->warehouse_id,
            'name' => (string) $plan->name,
            'abc_class' => $plan->abc_class,
            'interval_days' => (int) $plan->interval_days,
            'freezes_stock' => (bool) $plan->freezes_stock,
            'is_active' => (bool) $plan->is_active,
            'last_run_at' => $plan->last_run_at?->toIso8601String(),
            'next_run_on' => $plan->next_run_on?->toDateString(),
        ];
    }
}
