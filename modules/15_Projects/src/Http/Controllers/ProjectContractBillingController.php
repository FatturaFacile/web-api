<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Commands\ApproveAndInvoiceSalCommand;
use Modules\Projects\Commands\BillContractMilestoneCommand;
use Modules\Projects\Commands\ConsumePrepaidRetainerCommand;
use Modules\Projects\Commands\CreateSalProgressCertificateCommand;
use Modules\Projects\Commands\GenerateTimeAndMaterialsInvoiceDraftCommand;
use Modules\Projects\Commands\ReleaseGuaranteeRetentionCommand;
use Modules\Projects\Commands\RunProjectWipRecognitionCommand;
use Modules\Projects\Commands\TopUpPrepaidRetainerCommand;
use Modules\Projects\Commands\UpsertProjectBillingContractCommand;
use Modules\Projects\Http\Controllers\Concerns\PaginatesProjectRecords;
use Modules\Projects\Http\Requests\ApproveSalCertificateRequest;
use Modules\Projects\Http\Requests\BillProjectRequest;
use Modules\Projects\Http\Requests\ChangeBillingContractStatusRequest;
use Modules\Projects\Http\Requests\CreateSalCertificateRequest;
use Modules\Projects\Http\Requests\ReasonRequest;
use Modules\Projects\Http\Requests\ReleaseGuaranteeRetentionRequest;
use Modules\Projects\Http\Requests\RunProjectWipRecognitionRequest;
use Modules\Projects\Http\Requests\TopUpPrepaidRetainerRequest;
use Modules\Projects\Http\Requests\UpsertBillingContractRequest;
use Modules\Projects\Http\Requests\UpToDateRequest;
use Modules\Projects\Http\Resources\ProjectBillingContractResource;
use Modules\Projects\Http\Resources\ProjectInvoiceDraftResource;
use Modules\Projects\Http\Resources\ProjectRetainerLedgerEntryResource;
use Modules\Projects\Http\Resources\ProjectRetentionLedgerEntryResource;
use Modules\Projects\Http\Resources\ProjectSalCertificateResource;
use Modules\Projects\Http\Resources\ProjectWipValuationRunResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectWipValuationRun;
use Modules\Projects\Queries\GetPrepaidRetainerBalanceQuery;
use Modules\Projects\Queries\GetProjectRetentionBalanceQuery;
use Modules\Projects\Queries\GetProjectSalCertificatesQuery;
use Modules\Projects\Queries\GetProjectUnbilledWipSummaryQuery;
use Modules\Projects\Queries\GetUnbilledTimeAndMaterialsQuery;
use Modules\Projects\Services\ProjectBillingCommandHandler;
use Modules\Projects\Services\ProjectBillingQueryService;
use Modules\Projects\Support\Money;
use OpenApi\Attributes as OA;

/**
 * Project billing engine (Mod 081): billing contract, time and materials, milestones, prepaid retainer, SAL progress
 * certificates, guarantee retention and the period-end WIP valuation. Thin adapters to the command and query handlers.
 */
final class ProjectContractBillingController extends Controller
{
    use PaginatesProjectRecords;

    public function __construct(
        private readonly ProjectBillingCommandHandler $commands,
        private readonly ProjectBillingQueryService $queries,
    ) {}

    #[OA\Get(path: '/api/v1/projects/{id}/billing-contract', operationId: 'projectsBillingContractShow', summary: 'Billing contract of a project', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'The contract, or null when none was set up', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object', nullable: true)])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function showContract(string $id): JsonResponse
    {
        $contract = $this->queries->findContract(Project::findOrFail($id));

        return response()->json(['data' => $contract === null ? null : new ProjectBillingContractResource($contract)]);
    }

    #[OA\Put(path: '/api/v1/projects/{id}/billing-contract', operationId: 'projectsBillingContractUpsert', summary: 'Create or update the billing contract of a project', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'The contract', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function upsertContract(string $id, UpsertBillingContractRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);

        $attributes = $request->safe()->except(['expected_version']);
        $contract = $this->commands->upsertContract(new UpsertProjectBillingContractCommand($project->id, $attributes, $request->integer('expected_version') ?: null, $this->actor($request)));

        return response()->json(['data' => new ProjectBillingContractResource($contract)]);
    }

    #[OA\Post(path: '/api/v1/projects/{id}/billing-contract/status', operationId: 'projectsBillingContractStatus', summary: 'Change the status of the billing contract', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'The contract', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function changeContractStatus(string $id, ChangeBillingContractStatusRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);

        $contract = $this->commands->transitionContract($project->id, (string) $request->input('billing_status'), $request->integer('expected_version') ?: null, $this->actor($request));

        return response()->json(['data' => new ProjectBillingContractResource($contract)]);
    }

    #[OA\Get(path: '/api/v1/projects/{id}/billing/unbilled-summary', operationId: 'projectsBillingUnbilledSummary', summary: 'Unbilled and WIP summary of a project', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Unbilled time and materials, milestones, open SAL, retention and latest WIP', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function unbilledSummary(string $id): JsonResponse
    {
        return response()->json(['data' => $this->queries->unbilledWipSummary(new GetProjectUnbilledWipSummaryQuery(Project::findOrFail($id)->id))]);
    }

    #[OA\Get(path: '/api/v1/projects/{id}/billing/contract/time-and-materials', operationId: 'projectsBillingContractTimeAndMaterialsPreview', summary: 'Time and materials ready to invoice', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Lines, totals, markup and cap effect', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function timeAndMaterialsPreview(string $id, UpToDateRequest $request): JsonResponse
    {
        $upTo = (string) ($request->validated('up_to_date') ?? now()->toDateString());

        return response()->json(['data' => $this->queries->unbilledTimeAndMaterials(new GetUnbilledTimeAndMaterialsQuery(Project::findOrFail($id)->id, $upTo))]);
    }

    #[OA\Post(path: '/api/v1/projects/{id}/billing/contract/time-and-materials', operationId: 'projectsBillingContractTimeAndMaterialsDraft', summary: 'Draft the time and materials invoice of the contract', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 201, description: 'Draft invoice created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsInvoiceDraft')])),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function timeAndMaterialsDraft(string $id, BillProjectRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);

        $invoice = $this->commands->generateTimeAndMaterialsInvoiceDraft(new GenerateTimeAndMaterialsInvoiceDraftCommand(
            $project->id,
            (string) ($request->validated('up_to_date') ?? now()->toDateString()),
            Money::of($request->validated('tax_rate')),
            $this->actor($request),
        ));

        return response()->json(['message' => 'Draft invoice created', 'data' => new ProjectInvoiceDraftResource($invoice)], 201);
    }

    #[OA\Post(path: '/api/v1/projects/{id}/billing/contract/milestones/{milestoneId}', operationId: 'projectsBillingContractMilestone', summary: 'Bill a milestone of the contract', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId'), new OA\Parameter(ref: '#/components/parameters/ProjectsMilestoneId')],
        responses: [
            new OA\Response(response: 201, description: 'Draft invoice created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsInvoiceDraft')])),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function milestone(string $id, string $milestoneId, BillProjectRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);

        $invoice = $this->commands->billMilestone(new BillContractMilestoneCommand($project->id, $milestoneId, Money::of($request->validated('tax_rate')), $this->actor($request)));

        return response()->json(['message' => 'Draft invoice created', 'data' => new ProjectInvoiceDraftResource($invoice)], 201);
    }

    #[OA\Get(path: '/api/v1/projects/{id}/sal-certificates', operationId: 'projectsSalCertificatesIndex', summary: 'SAL certificates of a project', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Certificates in number order', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function salIndex(string $id): JsonResponse
    {
        $certificates = $this->queries->salCertificates(new GetProjectSalCertificatesQuery(Project::findOrFail($id)->id));

        return response()->json(['data' => ProjectSalCertificateResource::collection($certificates)]);
    }

    #[OA\Post(path: '/api/v1/projects/{id}/sal-certificates', operationId: 'projectsSalCertificatesStore', summary: 'Draft the next SAL certificate', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 201, description: 'The certificate', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function salStore(string $id, CreateSalCertificateRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);

        $sal = $this->commands->createSal(new CreateSalProgressCertificateCommand(
            $project->id,
            (string) $request->input('period_start_date'),
            (string) $request->input('period_end_date'),
            Money::of($request->input('cumulative_completion_percent')),
            $request->input('notes'),
            $this->actor($request),
        ));

        return response()->json(['data' => new ProjectSalCertificateResource($sal)], 201);
    }

    #[OA\Post(path: '/api/v1/projects/{id}/sal-certificates/{salId}/submit', operationId: 'projectsSalCertificatesSubmit', summary: 'Send a SAL certificate for customer approval', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId'), new OA\Parameter(name: 'salId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The certificate', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function salSubmit(string $id, string $salId, Request $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);

        return response()->json(['data' => new ProjectSalCertificateResource($this->commands->submitSal($project->id, $salId, $this->actor($request)))]);
    }

    #[OA\Post(path: '/api/v1/projects/{id}/sal-certificates/{salId}/approve-and-invoice', operationId: 'projectsSalCertificatesApproveAndInvoice', summary: 'Approve a SAL certificate and draft its invoice', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId'), new OA\Parameter(name: 'salId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The invoiced certificate', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function salApproveAndInvoice(string $id, string $salId, ApproveSalCertificateRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);

        $sal = $this->commands->approveAndInvoiceSal(new ApproveAndInvoiceSalCommand($project->id, $salId, Money::of($request->input('tax_rate')), $request->input('approved_by'), $this->actor($request)));

        return response()->json(['data' => new ProjectSalCertificateResource($sal)]);
    }

    #[OA\Post(path: '/api/v1/projects/{id}/sal-certificates/{salId}/cancel', operationId: 'projectsSalCertificatesCancel', summary: 'Cancel a SAL certificate that was not invoiced', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId'), new OA\Parameter(name: 'salId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The cancelled certificate', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function salCancel(string $id, string $salId, ReasonRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);

        return response()->json(['data' => new ProjectSalCertificateResource($this->commands->cancelSal($project->id, $salId, $request->input('reason'), $this->actor($request)))]);
    }

    #[OA\Get(path: '/api/v1/projects/{id}/retention', operationId: 'projectsRetentionBalance', summary: 'Guarantee retention balance of a project', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Withheld, released and held retention', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function retentionBalance(string $id): JsonResponse
    {
        return response()->json(['data' => $this->queries->retentionBalance(new GetProjectRetentionBalanceQuery(Project::findOrFail($id)->id))]);
    }

    #[OA\Post(path: '/api/v1/projects/{id}/retention/release', operationId: 'projectsRetentionRelease', summary: 'Release the guarantee retention', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 201, description: 'The release movement', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function retentionRelease(string $id, ReleaseGuaranteeRetentionRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);

        $entry = $this->commands->releaseRetention(new ReleaseGuaranteeRetentionCommand(
            $project->id,
            $request->filled('amount') ? Money::of($request->input('amount')) : null,
            (string) ($request->input('release_date') ?? now()->toDateString()),
            $request->boolean('acceptance_confirmed'),
            $request->input('milestone_id'),
            $request->input('reason'),
            $this->actor($request),
        ));

        return response()->json(['data' => new ProjectRetentionLedgerEntryResource($entry)], 201);
    }

    #[OA\Get(path: '/api/v1/projects/{id}/retainer', operationId: 'projectsRetainerBalance', summary: 'Prepaid retainer balance of a project', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Balance and latest movements', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function retainerBalance(string $id): JsonResponse
    {
        $result = $this->queries->prepaidRetainerBalance(new GetPrepaidRetainerBalanceQuery(Project::findOrFail($id)->id));

        return response()->json(['data' => ['balance' => $result['balance'], 'entries' => ProjectRetainerLedgerEntryResource::collection($result['entries'])]]);
    }

    #[OA\Post(path: '/api/v1/projects/{id}/retainer/top-up', operationId: 'projectsRetainerTopUp', summary: 'Top up the prepaid retainer', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 201, description: 'The top-up movement', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function retainerTopUp(string $id, TopUpPrepaidRetainerRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);

        $entry = $this->commands->topUpRetainer(new TopUpPrepaidRetainerCommand(
            $project->id,
            Money::of($request->input('hours')),
            Money::of($request->input('amount')),
            (string) ($request->input('date') ?? now()->toDateString()),
            $request->input('note'),
            $this->actor($request),
        ));

        return response()->json(['data' => new ProjectRetainerLedgerEntryResource($entry)], 201);
    }

    #[OA\Post(path: '/api/v1/projects/{id}/retainer/consume', operationId: 'projectsRetainerConsume', summary: 'Burn approved hours against the prepaid retainer', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'Hours and amount burned', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function retainerConsume(string $id, UpToDateRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);

        $result = $this->commands->consumeRetainer(new ConsumePrepaidRetainerCommand($project->id, (string) ($request->validated('up_to_date') ?? now()->toDateString()), $this->actor($request)));

        return response()->json(['data' => $result]);
    }

    #[OA\Get(path: '/api/v1/projects/wip-valuations', operationId: 'projectsWipValuationsIndex', summary: 'WIP valuation runs', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsPerPage'), new OA\Parameter(ref: '#/components/parameters/ProjectsPage'), new OA\Parameter(name: 'period_end_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'project_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Runs, most recent period first', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'meta', ref: '#/components/schemas/ProjectsPaginationMeta')]))])]
    public function wipIndex(Request $request): JsonResponse
    {
        $query = ProjectWipValuationRun::query()
            ->when($request->query('period_end_date'), fn ($q, mixed $date) => $q->whereDate('period_end_date', (string) $date))
            ->when($request->query('project_id'), fn ($q, mixed $projectId) => $q->where('project_id', (string) $projectId))
            ->orderByDesc('period_end_date')->orderBy('project_id');

        return $this->paginated($query->paginate($this->perPage($request)), ProjectWipValuationRunResource::class);
    }

    #[OA\Post(path: '/api/v1/projects/wip-valuations', operationId: 'projectsWipValuationsRun', summary: 'Run the period-end WIP valuation of all open projects, or of one', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        responses: [new OA\Response(response: 200, description: 'Runs and skipped projects', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')]))])]
    public function wipRun(RunProjectWipRecognitionRequest $request): JsonResponse
    {
        $this->authorize('create', Project::class);

        $projectId = $request->input('project_id');

        if ($projectId !== null) {
            $this->authorize('manage', Project::findOrFail($projectId));
        }

        return response()->json(['data' => $this->wipResult($this->commands->runWipRecognition(new RunProjectWipRecognitionCommand($projectId, (string) $request->input('period_end_date'), $request->input('valuation_method'), $this->actor($request))))]);
    }

    #[OA\Post(path: '/api/v1/projects/{id}/wip-valuations', operationId: 'projectsWipValuationRunOne', summary: 'Run the period-end WIP valuation of a project', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        responses: [
            new OA\Response(response: 200, description: 'The run', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function wipRunProject(string $id, RunProjectWipRecognitionRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);

        return response()->json(['data' => $this->wipResult($this->commands->runWipRecognition(new RunProjectWipRecognitionCommand($project->id, (string) $request->input('period_end_date'), $request->input('valuation_method'), $this->actor($request))))]);
    }

    /**
     * @param  array{runs: list<array{run: ProjectWipValuationRun, replayed: bool}>, skipped: list<array{project_id: string, reason: string}>}  $result
     * @return array<string, mixed>
     */
    private function wipResult(array $result): array
    {
        return [
            'runs' => array_map(static fn (array $entry): array => ['replayed' => $entry['replayed']] + (new ProjectWipValuationRunResource($entry['run']))->resolve(), $result['runs']),
            'skipped' => $result['skipped'],
        ];
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()?->getKey();
    }
}
