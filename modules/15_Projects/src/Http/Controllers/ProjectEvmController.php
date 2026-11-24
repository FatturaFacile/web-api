<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Commands\AllocateOverheadToProjectsCommand;
use Modules\Projects\Commands\ComputeProjectEvmSnapshotCommand;
use Modules\Projects\Commands\SetManualEtcCommand;
use Modules\Projects\Http\Controllers\Concerns\PaginatesProjectRecords;
use Modules\Projects\Http\Requests\AllocateOverheadRequest;
use Modules\Projects\Http\Requests\ComputeProjectEvmSnapshotRequest;
use Modules\Projects\Http\Requests\EvmScurveQueryRequest;
use Modules\Projects\Http\Requests\SetManualEtcRequest;
use Modules\Projects\Http\Resources\ProjectCostLedgerEntryResource;
use Modules\Projects\Http\Resources\ProjectFinancialSnapshotResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectCostLedgerEntry;
use Modules\Projects\Models\ProjectFinancialSnapshot;
use Modules\Projects\Queries\GetPortfolioEvmScurveQuery;
use Modules\Projects\Queries\GetProjectProfitabilityWaterfallQuery;
use Modules\Projects\Queries\GetWbsCostBreakdownQuery;
use Modules\Projects\Services\ProjectEvmCommandHandler;
use Modules\Projects\Services\ProjectEvmQueryService;
use OpenApi\Attributes as OA;

/**
 * Project profitability and earned value management (Mod 082): thin adapters to the command and query handlers.
 */
final class ProjectEvmController extends Controller
{
    use PaginatesProjectRecords;

    #[OA\Post(path: '/api/v1/projects/{id}/evm/compute', operationId: 'projectsEvmCompute', summary: 'Compute the financial snapshot of a project', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Project snapshot', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function compute(string $id, ComputeProjectEvmSnapshotRequest $request, ProjectEvmCommandHandler $handler): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);

        $snapshot = $handler->computeSnapshot(new ComputeProjectEvmSnapshotCommand($project->id, 'manual', (string) $request->user()?->getKey(), $request->input('as_of')));

        return response()->json(['data' => new ProjectFinancialSnapshotResource($snapshot)]);
    }

    #[OA\Get(path: '/api/v1/projects/{id}/evm/snapshots', operationId: 'projectsEvmFinancialSnapshots', summary: 'Financial snapshots of a project', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId'), new OA\Parameter(ref: '#/components/parameters/ProjectsPerPage'), new OA\Parameter(ref: '#/components/parameters/ProjectsPage')],
        responses: [
            new OA\Response(response: 200, description: 'Snapshots, most recent first', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'meta', ref: '#/components/schemas/ProjectsPaginationMeta')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function snapshots(string $id, Request $request): JsonResponse
    {
        $project = Project::findOrFail($id);

        return $this->paginated(ProjectFinancialSnapshot::query()->where('project_id', $project->id)->whereNull('wbs_node_id')->orderByDesc('snapshot_date')->paginate($this->perPage($request)), ProjectFinancialSnapshotResource::class);
    }

    #[OA\Put(path: '/api/v1/projects/{id}/evm/etc', operationId: 'projectsEvmSetManualEtc', summary: 'Set or clear the manual bottom-up ETC', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Recomputed project snapshot', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function setManualEtc(string $id, SetManualEtcRequest $request, ProjectEvmCommandHandler $handler): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);

        $estimate = $request->input('estimate_to_complete');
        $snapshot = $handler->setManualEtc(new SetManualEtcCommand(
            $project->id,
            $request->input('wbs_node_id'),
            $estimate === null ? null : (string) $estimate,
            $request->input('reason'),
            (string) $request->user()?->getKey(),
        ));

        return response()->json(['data' => new ProjectFinancialSnapshotResource($snapshot)]);
    }

    #[OA\Get(path: '/api/v1/projects/{id}/evm/wbs-breakdown', operationId: 'projectsEvmWbsBreakdown', summary: 'WBS cost breakdown', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'WBS tree with EVM figures', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function wbsBreakdown(string $id, ProjectEvmQueryService $queries): JsonResponse
    {
        return response()->json(['data' => $queries->wbsBreakdown(new GetWbsCostBreakdownQuery(Project::findOrFail($id)->id))]);
    }

    #[OA\Get(path: '/api/v1/projects/{id}/evm/waterfall', operationId: 'projectsEvmWaterfall', summary: 'Profitability waterfall', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Revenue to gross margin bridge', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function waterfall(string $id, ProjectEvmQueryService $queries): JsonResponse
    {
        return response()->json(['data' => $queries->waterfall(new GetProjectProfitabilityWaterfallQuery(Project::findOrFail($id)->id))]);
    }

    #[OA\Get(path: '/api/v1/projects/{id}/evm/ledger', operationId: 'projectsEvmLedger', summary: 'Project cost ledger', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId'), new OA\Parameter(ref: '#/components/parameters/ProjectsPerPage'), new OA\Parameter(ref: '#/components/parameters/ProjectsPage')],
        responses: [
            new OA\Response(response: 200, description: 'Ledger lines', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'meta', ref: '#/components/schemas/ProjectsPaginationMeta')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function ledger(string $id, Request $request): JsonResponse
    {
        $project = Project::findOrFail($id);

        return $this->paginated(ProjectCostLedgerEntry::query()->where('project_id', $project->id)->orderByDesc('posting_date')->paginate($this->perPage($request)), ProjectCostLedgerEntryResource::class);
    }

    #[OA\Get(path: '/api/v1/projects/evm/scurve', operationId: 'projectsEvmScurve', summary: 'Portfolio S-curve', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        responses: [new OA\Response(response: 200, description: 'Cumulative PV, EV, AC and EAC projection', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')]))])]
    public function scurve(EvmScurveQueryRequest $request, ProjectEvmQueryService $queries): JsonResponse
    {
        return response()->json(['data' => $queries->scurve(new GetPortfolioEvmScurveQuery(
            array_values(array_map('strval', (array) $request->input('project_ids', []))),
            (string) $request->input('granularity', 'month'),
            $request->input('from'),
            $request->input('to'),
        ))]);
    }

    #[OA\Post(path: '/api/v1/projects/evm/overhead-allocations', operationId: 'projectsEvmAllocateOverhead', summary: 'Allocate an overhead pool to projects', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        responses: [new OA\Response(response: 200, description: 'Allocation of the run', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')]))])]
    public function allocateOverhead(AllocateOverheadRequest $request, ProjectEvmCommandHandler $handler): JsonResponse
    {
        $this->authorize('create', Project::class);

        $weights = [];

        foreach ((array) $request->input('weights', []) as $projectId => $weight) {
            $weights[(string) $projectId] = (string) $weight;
        }

        return response()->json(['data' => $handler->allocateOverhead(new AllocateOverheadToProjectsCommand(
            (string) $request->input('run_key'),
            (string) $request->input('cost_center_id'),
            (string) $request->input('pool_amount'),
            (string) $request->input('period_start'),
            (string) $request->input('period_end'),
            (string) $request->input('driver'),
            $weights,
            array_values(array_map('strval', (array) $request->input('project_ids', []))),
        ))]);
    }
}
