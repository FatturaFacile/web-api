<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Contracts\ProjectRevenueRecognitionServiceInterface;
use Modules\Projects\DTOs\RevenueRecognitionDTO;
use Modules\Projects\Http\Requests\StoreRevenueRecognitionRequest;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectRevenueRecognition;
use OpenApi\Attributes as OA;

final class ProjectRevenueRecognitionController extends Controller
{
    public function __construct(
        private readonly ProjectRevenueRecognitionServiceInterface $revenueService
    ) {}

    /**
     * Returns the paginated list of projects of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/projects/{id}/revenue-recognitions',
        operationId: 'listProjectsRevenueRecognitions',
        description: 'Returns the paginated list of projects of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List projects',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of projects', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectsProjectRevenueRecognitionModel')), new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')]), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(string $projectId, Request $request): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        $recognitions = ProjectRevenueRecognition::where('project_id', $project->id)
            ->orderBy('accounting_period_date', 'desc')
            ->paginate((int) $request->query('per_page', 15));

        return response()->json([
            'data' => $recognitions->items(),
            'meta' => [
                'current_page' => $recognitions->currentPage(),
                'last_page' => $recognitions->lastPage(),
                'total' => $recognitions->total(),
            ],
        ]);
    }

    /**
     * Creates a new project in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/projects/{id}/revenue-recognitions',
        operationId: 'createProjectsRevenueRecognition',
        description: 'Creates a new project in the active tenant.',
        summary: 'Create project',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the project', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreRevenueRecognitionRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Project created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'project_id', type: 'string'), new OA\Property(property: 'accounting_period_date', type: 'string', format: 'date'), new OA\Property(property: 'recognition_method'), new OA\Property(property: 'cost_completion_percentage', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'), new OA\Property(property: 'cumulative_revenue', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'), new OA\Property(property: 'period_revenue_adjustment', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'), new OA\Property(property: 'wip_balance'), new OA\Property(property: 'unbilled_receivables'), new OA\Property(property: 'deferred_revenue')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(string $projectId, StoreRevenueRecognitionRequest $request): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        $dto = new RevenueRecognitionDTO(
            projectId: $project->id,
            accountingPeriodDate: $request->validated('accounting_period_date'),
            overrideCompletionPercentage: $request->validated('override_completion_percentage') !== null
                ? (float) $request->validated('override_completion_percentage')
                : null,
            userId: (string) auth()->id()
        );

        $recognition = $this->revenueService->calculateAndRecognize($dto);

        return response()->json([
            'message' => 'Revenue recognized and ledger/billing events dispatched',
            'data' => [
                'id' => $recognition->id,
                'project_id' => $recognition->project_id,
                'accounting_period_date' => $recognition->accounting_period_date?->toDateString(),
                'recognition_method' => $recognition->recognition_method,
                'cost_completion_percentage' => $recognition->cost_completion_percentage,
                'cumulative_revenue' => $recognition->cumulative_recognized_revenue,
                'period_revenue_adjustment' => $recognition->period_revenue_adjustment,
                'wip_balance' => $recognition->wip_balance,
                'unbilled_receivables' => $recognition->unbilled_receivables,
                'deferred_revenue' => $recognition->deferred_revenue,
            ],
        ], 201);
    }
}
