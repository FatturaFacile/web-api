<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Contracts\ProjectRevenueRecognitionServiceInterface;
use Modules\Projects\DTOs\RevenueRecognitionDTO;
use Modules\Projects\Http\Controllers\Concerns\PaginatesProjectRecords;
use Modules\Projects\Http\Requests\StoreRevenueRecognitionRequest;
use Modules\Projects\Http\Resources\ProjectRevenueRecognitionResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectRevenueRecognition;
use Modules\Projects\Support\Money;
use OpenApi\Attributes as OA;

/**
 * Revenue recognition runs of a project (percentage of completion or completed contract) and their ledger effect.
 */
final class ProjectRevenueRecognitionController extends Controller
{
    use PaginatesProjectRecords;

    public function __construct(private readonly ProjectRevenueRecognitionServiceInterface $revenueService) {}

    /**
     * Runs of the project, most recent first.
     */
    #[OA\Get(path: '/api/v1/projects/{id}/revenue-recognitions', operationId: 'projectsRevenueRecognitionsList', summary: 'List project revenue recognitions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId'), new OA\Parameter(ref: '#/components/parameters/ProjectsPerPage'), new OA\Parameter(ref: '#/components/parameters/ProjectsPage')],
        responses: [
            new OA\Response(response: 200, description: 'Recognition runs', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectsRevenueRecognition')), new OA\Property(property: 'meta', ref: '#/components/schemas/ProjectsPaginationMeta')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function index(string $id, Request $request): JsonResponse
    {
        $project = Project::findOrFail($id);

        return $this->paginated(ProjectRevenueRecognition::query()->where('project_id', $project->id)->orderByDesc('accounting_period_date')->paginate($this->perPage($request, 15)), ProjectRevenueRecognitionResource::class);
    }

    /**
     * Runs revenue recognition for an accounting date and posts the period adjustments to the general ledger.
     */
    #[OA\Post(path: '/api/v1/projects/{id}/revenue-recognitions', operationId: 'projectsRevenueRecognitionsCreate', summary: 'Recognise project revenue', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreRevenueRecognitionRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Revenue recognised', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsRevenueRecognition')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function store(string $id, StoreRevenueRecognitionRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);
        $override = $request->validated('override_completion_percentage');

        $recognition = $this->revenueService->calculateAndRecognize(new RevenueRecognitionDTO(
            projectId: $project->id,
            accountingPeriodDate: (string) $request->validated('accounting_period_date'),
            overrideCompletionPercentage: $override !== null ? Money::of($override) : null,
            userId: (string) $request->user()?->getKey(),
        ));

        return response()->json(['message' => 'Revenue recognised', 'data' => new ProjectRevenueRecognitionResource($recognition)], 201);
    }
}
