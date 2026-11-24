<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Planning\Http\Controllers\Concerns\InteractsWithPlanning;
use Modules\Planning\Http\Requests\ImportForecastRequest;
use Modules\Planning\Http\Requests\PlanLineRequest;
use Modules\Planning\Http\Resources\PlanLineResource;
use Modules\Planning\Models\PlanningPlanLine;
use Modules\Planning\Models\PlanningScenario;
use Modules\Planning\Services\PlanLineService;
use OpenApi\Attributes as OA;

/**
 * Demand and supply lines of a scenario and the import of the demand forecasts.
 */
final class PlanLineController extends Controller
{
    use InteractsWithPlanning;

    public function __construct(private readonly PlanLineService $lines) {}

    #[OA\Get(path: '/api/v1/planning/scenarios/{id}/lines', operationId: 'listPlanningLines', description: 'Demand and supply lines of a scenario by item and month. Filters: line_type, item_code.', summary: 'List plan lines', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId'), new OA\Parameter(name: 'line_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['demand', 'supply'])), new OA\Parameter(name: 'item_code', in: 'query', required: false, schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Lines', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PlanningLine'))])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
    ])]
    public function index(Request $request, string $id): AnonymousResourceCollection
    {
        $scenario = PlanningScenario::query()->findOrFail($id);
        $this->authorize('view', $scenario);
        $filters = $request->validate(['line_type' => ['nullable', Rule::in(PlanningPlanLine::Types)], 'item_code' => ['nullable', 'string', 'max:60']]);

        return PlanLineResource::collection(
            PlanningPlanLine::query()
                ->where('scenario_id', $scenario->id)
                ->when(isset($filters['line_type']), static fn ($query) => $query->where('line_type', $filters['line_type']))
                ->when(isset($filters['item_code']), static fn ($query) => $query->where('item_code', $filters['item_code']))
                ->orderBy('item_code')
                ->orderBy('period')
                ->orderBy('line_type')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/planning/scenarios/{id}/lines', operationId: 'storePlanningLine', description: 'Save the quantity of an item in a month on the demand or supply side of a draft scenario. The month is any date of it and must lie in the horizon; saving the same side, item and month again replaces the quantity. An item_id completes code and name from the item master.', summary: 'Save a plan line', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlanningLineRequest')), tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 201, description: 'Line saved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningLine')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function store(PlanLineRequest $request, string $id): JsonResponse
    {
        $scenario = PlanningScenario::query()->findOrFail($id);
        $this->authorize('update', $scenario);

        return (new PlanLineResource($this->lines->save($scenario, $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/planning/lines/{id}', operationId: 'updatePlanningLine', description: 'Change a line of a draft scenario.', summary: 'Update a plan line', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlanningLineRequest')), tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 200, description: 'Line updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlanningLine')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function update(PlanLineRequest $request, string $id): PlanLineResource
    {
        $line = PlanningPlanLine::query()->findOrFail($id);
        $this->authorize('update', $line);

        return new PlanLineResource($this->lines->update($line, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/planning/lines/{id}', operationId: 'deletePlanningLine', description: 'Delete a line of a draft scenario.', summary: 'Delete a plan line', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 204, description: 'Line deleted'),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $line = PlanningPlanLine::query()->findOrFail($id);
        $this->authorize('delete', $line);
        $this->lines->delete($line);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/planning/scenarios/{id}/import-forecasts', operationId: 'importPlanningForecasts', description: 'Fill the demand side of a draft scenario from the demand forecasts of the manufacturing module (read through the forecast source contract), summed per item and month inside the horizon. Existing demand lines are kept unless overwrite is set.', summary: 'Import demand forecasts', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/PlanningImportForecastRequest')), tags: ['Planning'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlanningId')], responses: [
        new OA\Response(response: 200, description: 'Import result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'created', type: 'integer'), new OA\Property(property: 'updated', type: 'integer'), new OA\Property(property: 'skipped', type: 'integer')], type: 'object')])),
        new OA\Response(ref: '#/components/responses/PlanningUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlanningForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlanningNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlanningUnprocessable', response: 422),
    ])]
    public function importForecasts(ImportForecastRequest $request, string $id): JsonResponse
    {
        $scenario = PlanningScenario::query()->findOrFail($id);
        $this->authorize('update', $scenario);

        return response()->json(['data' => $this->lines->importForecasts($scenario, (bool) ($request->validated()['overwrite'] ?? false))]);
    }
}
