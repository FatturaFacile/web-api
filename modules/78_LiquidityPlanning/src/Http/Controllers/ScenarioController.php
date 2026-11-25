<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\LiquidityPlanning\Http\Requests\ScenarioRequest;
use Modules\LiquidityPlanning\Http\Resources\ScenarioResource;
use Modules\LiquidityPlanning\Models\LiquidityScenario;
use Modules\LiquidityPlanning\Services\LiquidityTexts;
use Modules\LiquidityPlanning\Support\Levers;
use Modules\LiquidityPlanning\Support\ScenarioPresets;
use OpenApi\Attributes as OA;

/**
 * The what-if scenarios: the built-in presets and the scenarios saved by the users.
 */
final class ScenarioController extends Controller
{
    use ApiResponse;

    #[OA\Get(path: '/api/v1/liquidity-planning/scenarios/presets', operationId: 'listLiquidityPlanningScenarioPresets', summary: 'Built-in what-if presets', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Liquidity Planning'], responses: [new OA\Response(response: 200, description: 'The presets')])]
    public function presets(Request $request): JsonResponse
    {
        return $this->successResponse(ScenarioPresets::all(), LiquidityTexts::get('lpApi.message.presets', LiquidityTexts::locale()));
    }

    #[OA\Get(path: '/api/v1/liquidity-planning/scenarios', operationId: 'listLiquidityPlanningScenarios', summary: 'Saved what-if scenarios', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Liquidity Planning'], responses: [new OA\Response(response: 200, description: 'The scenarios')])]
    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(ScenarioResource::collection(LiquidityScenario::query()->orderBy('name')->get()), LiquidityTexts::get('lpApi.message.scenarios', LiquidityTexts::locale()));
    }

    #[OA\Post(path: '/api/v1/liquidity-planning/scenarios', operationId: 'createLiquidityPlanningScenario', summary: 'Save a what-if scenario', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Liquidity Planning'], responses: [new OA\Response(response: 201, description: 'The scenario'), new OA\Response(response: 422, description: 'Not valid')])]
    public function store(ScenarioRequest $request): JsonResponse
    {
        $scenario = LiquidityScenario::query()->create($this->attributes($request));

        return $this->successResponse(new ScenarioResource($scenario), LiquidityTexts::get('lpApi.message.scenario_saved', LiquidityTexts::locale()), 201);
    }

    #[OA\Put(path: '/api/v1/liquidity-planning/scenarios/{id}', operationId: 'updateLiquidityPlanningScenario', summary: 'Change a what-if scenario', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Liquidity Planning'], responses: [new OA\Response(response: 200, description: 'The scenario')])]
    public function update(ScenarioRequest $request, string $id): JsonResponse
    {
        $scenario = LiquidityScenario::query()->findOrFail($id);
        $scenario->update($this->attributes($request));

        return $this->successResponse(new ScenarioResource($scenario->refresh()), LiquidityTexts::get('lpApi.message.scenario_saved', LiquidityTexts::locale()));
    }

    #[OA\Delete(path: '/api/v1/liquidity-planning/scenarios/{id}', operationId: 'deleteLiquidityPlanningScenario', summary: 'Remove a what-if scenario', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Liquidity Planning'], responses: [new OA\Response(response: 200, description: 'Removed')])]
    public function destroy(Request $request, string $id): JsonResponse
    {
        LiquidityScenario::query()->findOrFail($id)->delete();

        return $this->successResponse(null, LiquidityTexts::get('lpApi.message.scenario_deleted', LiquidityTexts::locale()));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ScenarioRequest $request): array
    {
        return [
            'name' => (string) $request->validated('name'),
            'description' => $request->validated('description'),
            'levers' => Levers::fromArray((array) $request->validated('levers'))->toArray(),
        ];
    }
}
