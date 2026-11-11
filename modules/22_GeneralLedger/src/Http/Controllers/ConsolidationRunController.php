<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Http\Requests\RunConsolidationRequest;
use Modules\GeneralLedger\Http\Resources\ConsolidationRunResource;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Models\ConsolidationRun;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\StatementScheme;
use Modules\GeneralLedger\Services\Consolidation\ConsolidatedStatementService;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationService;
use Modules\GeneralLedger\Services\Consolidation\IntercompanyReconciliationService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Intercompany reconciliation, consolidation runs and consolidated statements. Every endpoint needs the access to the
 * ledger of every member entity of the group, not only of the active tenant (ConsolidationAccessGuard), and none is
 * open to API keys.
 */
final class ConsolidationRunController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ConsolidationService $consolidation,
        private readonly ConsolidatedStatementService $statements,
        private readonly IntercompanyReconciliationService $reconciliation,
    ) {}

    #[OA\Get(
        path: '/api/v1/general-ledger/consolidation/groups/{id}/intercompany-reconciliation',
        operationId: 'getIntercompanyReconciliation',
        description: 'For a fiscal period of the parent entity, compares the mapped intercompany accounts of the entities (receivable against payable, revenue against cost), in the entity currency and in group currency, and flags the pairs whose difference is above the tolerance of the relation. Balance sheet accounts are at the period end at the closing rate, income accounts year to date at the average rate. Needs the access to every member entity.',
        summary: 'Intercompany reconciliation report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'fiscal_period_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Report', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerIntercompanyReconciliation')])),
            new OA\Response(response: 403, description: 'The ledger of a member entity is not accessible', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error or missing exchange rate', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function reconciliation(RunConsolidationRequest $request, string $id): JsonResponse
    {
        $group = ConsolidationGroup::query()->findOrFail($id);
        $period = FiscalPeriod::query()->findOrFail($request->validated('fiscal_period_id'));

        return $this->successResponse($this->reconciliation->report($request->user(), $group, $period));
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/consolidation/groups/{id}/runs',
        operationId: 'listConsolidationRuns',
        description: 'The runs of a group, newest first, without adjustments.',
        summary: 'List consolidation runs',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'fiscal_period_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Runs', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerConsolidationRunResource'))])),
            new OA\Response(response: 403, description: 'The ledger of a member entity is not accessible', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request, string $id): JsonResponse
    {
        $group = ConsolidationGroup::query()->findOrFail($id);
        $this->consolidation->assertCanRead($request->user(), $group);

        $runs = ConsolidationRun::query()
            ->where('group_id', $group->id)
            ->when($request->query('fiscal_period_id'), static fn ($query, $periodId) => $query->where('fiscal_period_id', $periodId))
            ->orderByDesc('period_end')
            ->orderByDesc('version')
            ->get();

        return $this->successResponse(ConsolidationRunResource::collection($runs));
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/consolidation/groups/{id}/runs',
        operationId: 'createConsolidationRun',
        description: 'Consolidates the group for a fiscal period of the parent entity: translates and aggregates the trial balances of the members, generates the eliminations and stores everything as a new version of the run, apart from the ledgers of the entities. Nothing is posted in any entity. Warnings (differences, unclosed periods, skipped mappings) are returned with the run. Needs the access to every member entity; a missing exchange rate or a proportional member answers 422.',
        summary: 'Run consolidation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerRunConsolidationRequest')),
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Run created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerConsolidationRunResource')])),
            new OA\Response(response: 403, description: 'The ledger of a member entity is not accessible', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error, missing exchange rate or unsupported method', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(RunConsolidationRequest $request, string $id): JsonResponse
    {
        $group = ConsolidationGroup::query()->findOrFail($id);
        $period = FiscalPeriod::query()->findOrFail($request->validated('fiscal_period_id'));
        $run = $this->consolidation->run($request->user(), $group, $period);

        return $this->successResponse(new ConsolidationRunResource($run->load('adjustments.lines')), 'Consolidation run created', Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/consolidation/runs/{runId}',
        operationId: 'getConsolidationRun',
        description: 'A run with its members, rates, warnings, key totals and the adjustments it generated.',
        summary: 'Show consolidation run',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Run', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerConsolidationRunResource')])),
            new OA\Response(response: 403, description: 'The ledger of a member entity is not accessible', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(Request $request, string $runId): JsonResponse
    {
        $run = ConsolidationRun::query()->findOrFail($runId);
        $this->consolidation->assertCanReadRun($request->user(), $run);

        return $this->successResponse(new ConsolidationRunResource($run->load('adjustments.lines')));
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/consolidation/runs/{runId}/verify',
        operationId: 'verifyConsolidationRun',
        description: 'Recomputes the inputs of the run (members, shares, rates, mappings, entity balances) from the live ledgers and compares their fingerprint with the stored one: `matches` is false when a ledger, a rate or the configuration changed after the run.',
        summary: 'Verify consolidation run',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Verification', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'matches', type: 'boolean'),
                new OA\Property(property: 'stored_fingerprint', type: 'string'),
                new OA\Property(property: 'current_fingerprint', type: 'string'),
            ])])),
            new OA\Response(response: 403, description: 'The ledger of a member entity is not accessible', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function verify(Request $request, string $runId): JsonResponse
    {
        return $this->successResponse($this->consolidation->verify($request->user(), ConsolidationRun::query()->findOrFail($runId)));
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/consolidation/runs/{runId}/lock',
        operationId: 'lockConsolidationRun',
        description: 'Locks the run as the official consolidation of its period. Allowed only while the inputs are unchanged (same fingerprint) and no other run of the period is locked; a locked run cannot be deleted. Audit-logged.',
        summary: 'Lock consolidation run',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Run locked', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerConsolidationRunResource')])),
            new OA\Response(response: 403, description: 'The ledger of a member entity is not accessible', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Already locked, another run is locked, or the inputs changed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function lock(Request $request, string $runId): JsonResponse
    {
        $run = $this->consolidation->lock($request->user(), ConsolidationRun::query()->findOrFail($runId));

        return $this->successResponse(new ConsolidationRunResource($run), 'Consolidation run locked');
    }

    #[OA\Delete(
        path: '/api/v1/general-ledger/consolidation/runs/{runId}',
        operationId: 'deleteConsolidationRun',
        description: 'Deletes a run that is not locked. Audit-logged.',
        summary: 'Delete consolidation run',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Run deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'The ledger of a member entity is not accessible', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The run is locked', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(Request $request, string $runId): JsonResponse
    {
        $this->consolidation->delete($request->user(), ConsolidationRun::query()->findOrFail($runId));

        return $this->successResponse(null, 'Consolidation run deleted');
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/consolidation/runs/{runId}/trial-balance',
        operationId: 'getConsolidatedTrialBalance',
        description: 'Consolidated trial balance by account code: the translated balances of the fully consolidated members, the adjustments and their sum, in group currency.',
        summary: 'Consolidated trial balance',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Trial balance', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerConsolidatedStatement')])),
            new OA\Response(response: 403, description: 'The ledger of a member entity is not accessible', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function trialBalance(Request $request, string $runId): JsonResponse
    {
        return $this->successResponse($this->statements->trialBalance($request->user(), ConsolidationRun::query()->findOrFail($runId)));
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/consolidation/runs/{runId}/balance-sheet',
        operationId: 'getConsolidatedBalanceSheet',
        description: 'Consolidated balance sheet at the period end in group currency, classified by account type, with the result of the period inside equity and the non-controlling interests as a split of equity.',
        summary: 'Consolidated balance sheet',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Balance sheet', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerConsolidatedStatement')])),
            new OA\Response(response: 403, description: 'The ledger of a member entity is not accessible', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function balanceSheet(Request $request, string $runId): JsonResponse
    {
        return $this->successResponse($this->statements->balanceSheet($request->user(), ConsolidationRun::query()->findOrFail($runId)));
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/consolidation/runs/{runId}/income-statement',
        operationId: 'getConsolidatedIncomeStatement',
        description: 'Consolidated income statement year to date in group currency, classified by account type, with the share attributable to non-controlling interests.',
        summary: 'Consolidated income statement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Income statement', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerConsolidatedStatement')])),
            new OA\Response(response: 403, description: 'The ledger of a member entity is not accessible', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function incomeStatement(Request $request, string $runId): JsonResponse
    {
        return $this->successResponse($this->statements->incomeStatement($request->user(), ConsolidationRun::query()->findOrFail($runId)));
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/consolidation/runs/{runId}/reclassified',
        operationId: 'getConsolidatedReclassifiedStatement',
        description: 'The consolidated accounts of the run in a statement scheme of the active tenant (statement-schemes). A balance sheet scheme also sees the result of the period as the account CONS-RESULT. Accounts that no row picks up are listed.',
        summary: 'Consolidated reclassified statement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Consolidation'],
        parameters: [
            new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'scheme_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Reclassified statement', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerConsolidatedStatement')])),
            new OA\Response(response: 403, description: 'The ledger of a member entity is not accessible', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function reclassified(Request $request, string $runId): JsonResponse
    {
        $request->validate(['scheme_id' => ['required', 'uuid']]);
        $scheme = StatementScheme::query()->findOrFail($request->query('scheme_id'));

        return $this->successResponse($this->statements->reclassified($request->user(), ConsolidationRun::query()->findOrFail($runId), $scheme));
    }
}
