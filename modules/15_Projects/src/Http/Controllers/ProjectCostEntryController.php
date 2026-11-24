<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Actions\RecordJobCostEntryAction;
use Modules\Projects\DTOs\RecordJobCostDTO;
use Modules\Projects\Http\Controllers\Concerns\PaginatesProjectRecords;
use Modules\Projects\Http\Requests\StoreProjectCostEntryRequest;
use Modules\Projects\Http\Resources\ProjectCostEntryResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Projects\Support\Money;
use OpenApi\Attributes as OA;

/**
 * Job costing of a project: the list of booked costs and manual cost entries.
 */
final class ProjectCostEntryController extends Controller
{
    use PaginatesProjectRecords;

    /**
     * Cost entries of the project, filtered by category, task or approval status.
     */
    #[OA\Get(path: '/api/v1/projects/{id}/costs', operationId: 'projectsCostsList', summary: 'List project costs', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/ProjectsId'),
            new OA\Parameter(name: 'cost_category', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'task_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'approval_status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'rejected'])),
            new OA\Parameter(ref: '#/components/parameters/ProjectsPerPage'),
            new OA\Parameter(ref: '#/components/parameters/ProjectsPage'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cost entries', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectsCostEntry')), new OA\Property(property: 'meta', ref: '#/components/schemas/ProjectsPaginationMeta')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function index(string $id, Request $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $entries = ProjectCostEntry::query()
            ->where('project_id', $project->id)
            ->when($request->query('cost_category'), fn ($query, $category) => $query->where('cost_category', $category))
            ->when($request->query('task_id'), fn ($query, $taskId) => $query->where('project_task_id', $taskId))
            ->when($request->query('approval_status'), fn ($query, $status) => $query->where('approval_status', $status))
            ->orderByDesc('incurred_date')
            ->gridPaginate($this->perPage($request));

        return $this->paginated($entries, ProjectCostEntryResource::class);
    }

    /**
     * Books a manual cost entry referencing its source document (managers only).
     */
    #[OA\Post(path: '/api/v1/projects/{id}/costs', operationId: 'projectsCostsCreate', summary: 'Record project cost', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreCostEntryRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Cost booked (or the existing entry of the same source document)', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsCostEntry')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function store(string $id, StoreProjectCostEntryRequest $request, RecordJobCostEntryAction $action): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);
        $data = $request->validated();
        $total = isset($data['total_cost']) ? Money::of($data['total_cost']) : Money::amount(Money::mul($data['quantity'], $data['unit_cost']));

        $entry = $action->execute(new RecordJobCostDTO(
            projectId: $project->id,
            costCategory: $data['cost_category'],
            costSourceType: $data['cost_source_type'],
            costSourceId: $data['cost_source_id'],
            quantity: Money::of($data['quantity']),
            unitCost: Money::of($data['unit_cost']),
            totalCost: $total,
            incurredDate: $data['incurred_date'],
            phaseId: $data['phase_id'] ?? null,
            taskId: $data['task_id'] ?? null,
            uom: $data['uom'] ?? 'PCE',
            isBillable: (bool) ($data['is_billable'] ?? true),
            allocations: $data['allocations'] ?? [],
            userId: (string) $request->user()?->getKey(),
            companyId: $project->company_id,
            description: $data['description'] ?? null,
        ));

        return response()->json(['message' => 'Cost recorded', 'data' => new ProjectCostEntryResource($entry->load('allocations'))], 201);
    }
}
