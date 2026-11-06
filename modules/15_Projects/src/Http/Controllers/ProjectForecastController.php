<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Projects\Contracts\ProjectForecastServiceInterface;
use Modules\Projects\Models\Project;
use OpenApi\Attributes as OA;

final class ProjectForecastController extends Controller
{
    public function __construct(
        private readonly ProjectForecastServiceInterface $forecastService
    ) {}

    /**
     * Returns a single project of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/projects/{id}/forecast',
        operationId: 'getProjectsForecast',
        description: 'Returns a single project of the active tenant.',
        summary: 'Get project',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'project_id', type: 'string'), new OA\Property(property: 'project_number', type: 'string'), new OA\Property(property: 'bac', type: 'number'), new OA\Property(property: 'ac', type: 'number'), new OA\Property(property: 'cpi', type: 'number'), new OA\Property(property: 'spi', type: 'number'), new OA\Property(property: 'eac', type: 'number'), new OA\Property(property: 'etc', type: 'number'), new OA\Property(property: 'vac', type: 'number'), new OA\Property(property: 'projected_margin_amount', type: 'number'), new OA\Property(property: 'projected_margin_percentage', type: 'number'), new OA\Property(property: 'cash_inflows', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'milestone_name', type: 'string'), new OA\Property(property: 'date', type: 'string'), new OA\Property(property: 'amount', type: 'number')])), new OA\Property(property: 'cash_outflows', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'category', type: 'string'), new OA\Property(property: 'date', type: 'string'), new OA\Property(property: 'amount', type: 'number')])), new OA\Property(property: 'net_cash_flow', type: 'number')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $projectId): JsonResponse
    {
        $project = Project::with(['financialSummary', 'milestones', 'phases'])->findOrFail($projectId);

        $forecast = $this->forecastService->generateProjectForecast($project);

        return response()->json([
            'data' => $forecast,
        ]);
    }
}
