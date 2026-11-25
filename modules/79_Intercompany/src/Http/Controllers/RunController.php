<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Intercompany\Enums\RunStatus;
use Modules\Intercompany\Enums\Standard;
use Modules\Intercompany\Http\Requests\AdvanceRunRequest;
use Modules\Intercompany\Http\Requests\ReopenRunRequest;
use Modules\Intercompany\Http\Requests\StatementsQueryRequest;
use Modules\Intercompany\Http\Requests\StoreManualAdjustmentRequest;
use Modules\Intercompany\Http\Requests\StorePackageRequest;
use Modules\Intercompany\Http\Resources\ManualAdjustmentResource;
use Modules\Intercompany\Http\Resources\PackageResource;
use Modules\Intercompany\Models\ConsolidationPackage;
use Modules\Intercompany\Models\ManualAdjustment;
use Modules\Intercompany\Services\ConsolidatedCashFlowService;
use Modules\Intercompany\Services\ConsolidationPackageService;
use Modules\Intercompany\Services\ConsolidationSchedulesService;
use Modules\Intercompany\Services\EliminationLedgerService;
use Modules\Intercompany\Services\ManualAdjustmentService;
use Modules\Intercompany\Services\RunFigures;
use Modules\Intercompany\Services\RunWorkflowService;
use Modules\Intercompany\Services\SettingsService;
use Modules\Intercompany\Services\StatementsService;
use Modules\Intercompany\Support\GroupScope;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Consolidation run workflow, elimination ledger, schedules, statements by standard, cash flow, manual adjustments and the
 * consolidated package. All of it needs the access to the ledger of every entity of the group (checked in the services).
 */
final class RunController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly GroupScope $scope,
        private readonly RunWorkflowService $workflow,
        private readonly EliminationLedgerService $ledger,
        private readonly ConsolidationSchedulesService $schedules,
        private readonly StatementsService $statements,
        private readonly ConsolidatedCashFlowService $cashFlow,
        private readonly ManualAdjustmentService $manual,
        private readonly ConsolidationPackageService $packages,
        private readonly RunFigures $figures,
        private readonly SettingsService $settings,
    ) {}

    #[OA\Get(path: '/api/v1/intercompany/runs/{runId}/workflow', operationId: 'intercompanyRunWorkflow', summary: 'Workflow state and history of a run', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Workflow')])]
    public function workflow(Request $request, string $runId): JsonResponse
    {
        Gate::authorize('intercompany.view');

        return $this->successResponse($this->workflowPayload($request, $runId), 'Run workflow');
    }

    #[OA\Post(path: '/api/v1/intercompany/runs/{runId}/workflow/advance', operationId: 'intercompanyAdvanceRun', summary: 'Move a run to the next workflow step', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Workflow'), new OA\Response(response: 409, description: 'Stale version or illegal transition')])]
    public function advance(AdvanceRunRequest $request, string $runId): JsonResponse
    {
        $run = $this->scope->run($runId);
        $this->workflow->advance($request->user(), $run, RunStatus::from((string) $request->validated('to')), (int) $request->validated('expected_version'));

        return $this->successResponse($this->workflowPayload($request, $runId), 'Run workflow updated');
    }

    #[OA\Post(path: '/api/v1/intercompany/runs/{runId}/reopen', operationId: 'intercompanyReopenRun', summary: 'Reopen a locked run with a reason', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Workflow'), new OA\Response(response: 409, description: 'Stale version or run not locked')])]
    public function reopen(ReopenRunRequest $request, string $runId): JsonResponse
    {
        $run = $this->scope->run($runId);
        $this->workflow->reopen($request->user(), $run, (string) $request->validated('reason'), (int) $request->validated('expected_version'));

        return $this->successResponse($this->workflowPayload($request, $runId), 'Run reopened');
    }

    #[OA\Get(path: '/api/v1/intercompany/runs/{runId}/elimination-ledger', operationId: 'intercompanyEliminationLedger', summary: 'Elimination and consolidation journal of a run', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Ledger')])]
    public function eliminationLedger(Request $request, string $runId): JsonResponse
    {
        Gate::authorize('intercompany.view');

        return $this->successResponse($this->ledger->ledger($request->user(), $this->scope->run($runId), $request->query('type'), $request->query('entity_tenant_id')), 'Elimination ledger');
    }

    #[OA\Get(path: '/api/v1/intercompany/runs/{runId}/nci-schedule', operationId: 'intercompanyNciSchedule', summary: 'Schedule of non-controlling interests', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Schedule')])]
    public function nci(Request $request, string $runId): JsonResponse
    {
        Gate::authorize('intercompany.view');

        return $this->successResponse($this->schedules->nci($request->user(), $this->scope->run($runId)), 'Non-controlling interests');
    }

    #[OA\Get(path: '/api/v1/intercompany/runs/{runId}/shareholdings', operationId: 'intercompanyShareholdings', summary: 'Schedule of shareholdings and scope of consolidation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Schedule')])]
    public function shareholdings(Request $request, string $runId): JsonResponse
    {
        Gate::authorize('intercompany.view');

        return $this->successResponse($this->schedules->shareholdings($request->user(), $this->scope->run($runId)), 'Shareholdings');
    }

    #[OA\Get(path: '/api/v1/intercompany/runs/{runId}/statements', operationId: 'intercompanyStatements', summary: 'Consolidated statements in a reporting standard', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'standard', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['fer30', 'ifrs10', 'oic17']))], responses: [new OA\Response(response: 200, description: 'Statements')])]
    public function statements(StatementsQueryRequest $request, string $runId): JsonResponse
    {
        $run = $this->scope->run($runId);
        $standard = Standard::tryFrom((string) $request->validated('standard')) ?? Standard::from((string) $this->settings->forGroup($this->scope->groupOfRun($run))->default_standard);

        return $this->successResponse($this->statements->statements($request->user(), $run, $standard, (string) ($request->validated('statement') ?? 'all')), 'Consolidated statements');
    }

    #[OA\Get(path: '/api/v1/intercompany/runs/{runId}/cash-flow', operationId: 'intercompanyCashFlow', summary: 'Consolidated cash flow statement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Cash flow')])]
    public function cashFlow(StatementsQueryRequest $request, string $runId): JsonResponse
    {
        return $this->successResponse($this->cashFlow->cashFlow($request->user(), $this->scope->run($runId)), 'Consolidated cash flow');
    }

    #[OA\Get(path: '/api/v1/intercompany/runs/{runId}/manual-adjustments', operationId: 'intercompanyListManualAdjustments', summary: 'Manual adjustments of a run', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Adjustments')])]
    public function manualAdjustments(Request $request, string $runId): JsonResponse
    {
        Gate::authorize('intercompany.view');
        $run = $this->scope->run($runId);
        $this->scope->assertReadable($request->user(), $this->scope->groupOfRun($run));

        return $this->successResponse(ManualAdjustmentResource::collection(ManualAdjustment::query()->where('run_id', $run->id)->orderBy('created_at')->get()), 'Manual adjustments');
    }

    #[OA\Post(path: '/api/v1/intercompany/runs/{runId}/manual-adjustments', operationId: 'intercompanyStoreManualAdjustment', summary: 'Add a manual adjustment to a run', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Adjustment'), new OA\Response(response: 409, description: 'Run is locked')])]
    public function storeManualAdjustment(StoreManualAdjustmentRequest $request, string $runId): JsonResponse
    {
        $adjustment = $this->manual->create($request->user(), $this->scope->run($runId), (string) $request->validated('reference'), (string) $request->validated('reason'), $request->validated('lines'));

        return $this->successResponse(new ManualAdjustmentResource($adjustment), 'Manual adjustment added', Response::HTTP_CREATED);
    }

    #[OA\Delete(path: '/api/v1/intercompany/manual-adjustments/{id}', operationId: 'intercompanyVoidManualAdjustment', summary: 'Void a manual adjustment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Adjustment')])]
    public function voidManualAdjustment(Request $request, string $id): JsonResponse
    {
        Gate::authorize('intercompany.manage');

        return $this->successResponse(new ManualAdjustmentResource($this->manual->void($request->user(), ManualAdjustment::query()->findOrFail($id))), 'Manual adjustment voided');
    }

    #[OA\Get(path: '/api/v1/intercompany/runs/{runId}/packages', operationId: 'intercompanyListPackages', summary: 'Consolidated packages of a run', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Packages')])]
    public function packages(Request $request, string $runId): JsonResponse
    {
        Gate::authorize('intercompany.view');
        $run = $this->scope->run($runId);
        $this->scope->assertReadable($request->user(), $this->scope->groupOfRun($run));

        return $this->successResponse(PackageResource::collection(ConsolidationPackage::query()->where('run_id', $run->id)->orderByDesc('generated_at')->get(['id', 'tenant_id', 'run_id', 'standard', 'checksum', 'generated_at'])), 'Packages');
    }

    #[OA\Post(path: '/api/v1/intercompany/runs/{runId}/packages', operationId: 'intercompanyGeneratePackage', summary: 'Generate the consolidated package of a run', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Package')])]
    public function generatePackage(StorePackageRequest $request, string $runId): JsonResponse
    {
        $package = $this->packages->generate($request->user(), $this->scope->run($runId), Standard::from((string) $request->validated('standard')));

        return $this->successResponse(new PackageResource($package), 'Package generated', Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/intercompany/packages/{id}', operationId: 'intercompanyShowPackage', summary: 'A consolidated package with its payload', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Package')])]
    public function showPackage(Request $request, string $id): JsonResponse
    {
        Gate::authorize('intercompany.view');

        return $this->successResponse(new PackageResource($this->package($request, $id), true), 'Package');
    }

    #[OA\Get(path: '/api/v1/intercompany/packages/{id}/download', operationId: 'intercompanyDownloadPackage', summary: 'Download a consolidated package (json, csv, xlsx)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['json', 'csv', 'xlsx']))], responses: [new OA\Response(response: 200, description: 'File')])]
    public function downloadPackage(Request $request, string $id): Response|StreamedResponse
    {
        Gate::authorize('intercompany.view');
        $package = $this->package($request, $id);
        $format = in_array($request->query('format'), ['csv', 'xlsx'], true) ? (string) $request->query('format') : 'json';
        $name = "consolidated-package-{$package->standard}-{$package->id}.{$format}";

        return match ($format) {
            'csv' => response($this->packages->toCsv($package), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}\""]),
            'xlsx' => response()->download($this->packages->toXlsx($package), $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true),
            default => response((string) json_encode($package->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 200, ['Content-Type' => 'application/json', 'Content-Disposition' => "attachment; filename=\"{$name}\""]),
        };
    }

    private function package(Request $request, string $id): ConsolidationPackage
    {
        $package = ConsolidationPackage::query()->findOrFail($id);
        $run = $this->scope->run((string) $package->run_id);
        $this->scope->assertReadable($request->user(), $this->scope->groupOfRun($run));

        return $package;
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowPayload(Request $request, string $runId): array
    {
        $run = $this->scope->run($runId);
        $state = $this->workflow->state($request->user(), $run);

        return [
            'run_id' => (string) $run->id,
            'status' => $state->status,
            'version' => (int) $state->version,
            'allowed_transitions' => $this->workflow->allowed($state),
            'manual_adjustments' => $this->figures->manualAdjustments($run)->count(),
            'history' => $this->workflow->history($run)->map(static fn ($event): array => [
                'id' => (string) $event->id,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'action' => $event->action,
                'reason' => $event->reason,
                'user_id' => $event->user_id,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
