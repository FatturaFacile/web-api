<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Projects\Contracts\ProjectForecastServiceInterface;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\ProjectProfitabilityService;
use OpenApi\Attributes as OA;

/**
 * Read-only reports of a project: forecast to completion and profitability.
 */
final class ProjectForecastController extends Controller
{
    /**
     * Forecast to completion: EVM estimate, remaining budget by category, expected billing and outflows.
     */
    #[OA\Get(path: '/api/v1/projects/{id}/forecast', operationId: 'projectsForecast', summary: 'Project forecast', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Forecast', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsForecast')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function show(string $id, ProjectForecastServiceInterface $forecasts): JsonResponse
    {
        return response()->json(['data' => $forecasts->generateProjectForecast(Project::findOrFail($id))]);
    }

    /**
     * Profitability: revenue billed and recognised, cost by category, commitments, margin, forecast and hours.
     */
    #[OA\Get(path: '/api/v1/projects/{id}/profitability', operationId: 'projectsProfitability', summary: 'Project profitability report', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Profitability', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsProfitability')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function profitability(string $id, ProjectProfitabilityService $profitability): JsonResponse
    {
        return response()->json(['data' => $profitability->report(Project::findOrFail($id))]);
    }
}
