<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Http\Requests\SetLedgerBudgetLinesRequest;
use Modules\GeneralLedger\Http\Requests\StoreLedgerBudgetRequest;
use Modules\GeneralLedger\Http\Resources\LedgerBudgetResource;
use Modules\GeneralLedger\Models\LedgerBudget;
use Modules\GeneralLedger\Services\LedgerBudgetService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Company budgets on the ledger. The budgets of a project live under the projects API.
 */
final class LedgerBudgetController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly LedgerBudgetService $budgets,
        private readonly TenantContext $tenantContext,
    ) {}

    #[OA\Get(
        path: '/api/v1/general-ledger/budgets',
        operationId: 'listLedgerBudgets',
        description: 'Budget versions of the tenant.',
        summary: 'List ledger budgets',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Budgets'],
        parameters: [new OA\Parameter(name: 'fiscal_year_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Budgets', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerBudgetResource'))])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $budgets = LedgerBudget::query()
            ->when($request->query('fiscal_year_id'), static fn ($query, $id) => $query->where('fiscal_year_id', $id))
            ->orderBy('name')
            ->orderByDesc('version')
            ->get();

        return $this->successResponse(LedgerBudgetResource::collection($budgets));
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/budgets',
        operationId: 'createLedgerBudget',
        description: 'Create version 1 of a budget as a draft.',
        summary: 'Create ledger budget',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreBudgetRequest')),
        tags: ['General Ledger Budgets'],
        responses: [
            new OA\Response(response: 201, description: 'Budget created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerBudgetResource')])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreLedgerBudgetRequest $request): JsonResponse
    {
        $budget = $this->budgets->create((string) $this->tenantContext->getTenantId(), $request->validated(), (string) $request->user()->id);

        return $this->successResponse(new LedgerBudgetResource($budget), 'Budget created', Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/budgets/{id}',
        operationId: 'getLedgerBudget',
        description: 'A budget version with its lines.',
        summary: 'Show ledger budget',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Budgets'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Budget', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerBudgetResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new LedgerBudgetResource(LedgerBudget::query()->with('lines')->findOrFail($id)));
    }

    #[OA\Put(
        path: '/api/v1/general-ledger/budgets/{id}/lines',
        operationId: 'setLedgerBudgetLines',
        description: 'Set monthly amounts on a draft, by account and cost centre, in the natural sign of the account. An approved version answers 409.',
        summary: 'Set budget lines',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerSetBudgetLinesRequest')),
        tags: ['General Ledger Budgets'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Lines set', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerBudgetResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Not a draft', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function setLines(SetLedgerBudgetLinesRequest $request, string $id): JsonResponse
    {
        $budget = $this->budgets->setLines(LedgerBudget::query()->findOrFail($id), $request->validated('lines'));

        return $this->successResponse(new LedgerBudgetResource($budget->load('lines')), 'Lines set');
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/budgets/{id}/new-version',
        operationId: 'createLedgerBudgetVersion',
        description: 'Copy a version into the next draft. Earlier versions stay untouched.',
        summary: 'Create budget version',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Budgets'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Version created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerBudgetResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function newVersion(Request $request, string $id): JsonResponse
    {
        $copy = $this->budgets->newVersion(LedgerBudget::query()->findOrFail($id), (string) $request->user()->id);

        return $this->successResponse(new LedgerBudgetResource($copy->load('lines')), 'Version created', Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/budgets/{id}/approve',
        operationId: 'approveLedgerBudget',
        description: 'Approve a draft. The version approved before it becomes superseded.',
        summary: 'Approve budget',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Budgets'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Approved', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerBudgetResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Not a draft', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->successResponse(new LedgerBudgetResource($this->budgets->approve(LedgerBudget::query()->findOrFail($id), (string) $request->user()->id)), 'Budget approved');
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/budgets/{id}/variance',
        operationId: 'getLedgerBudgetVariance',
        description: 'Actual versus budget per account and cost centre. Unbudgeted income accounts appear too.',
        summary: 'Budget variance',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Budgets'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'period_from', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 12)),
            new OA\Parameter(name: 'period_to', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 12)),
            new OA\Parameter(name: 'cost_center_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Variance', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerBudgetVariance')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function variance(Request $request, string $id): JsonResponse
    {
        $budget = LedgerBudget::query()->with('lines')->findOrFail($id);

        return $this->successResponse($this->budgets->variance(
            $budget,
            max(1, (int) $request->query('period_from', 1)),
            min(12, (int) $request->query('period_to', 12)),
            $request->query('cost_center_id')
        ));
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/budgets/{id}/rolling-forecast',
        operationId: 'getLedgerRollingForecast',
        description: 'Full-year outlook: actual amounts up to `as_of_period`, then the budget or the monthly run rate.',
        summary: 'Rolling forecast',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Budgets'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'as_of_period', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 0, maximum: 12)),
            new OA\Parameter(name: 'method', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['budget', 'run_rate'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Forecast', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerRollingForecast')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function rollingForecast(Request $request, string $id): JsonResponse
    {
        $budget = LedgerBudget::query()->with('lines')->findOrFail($id);
        $method = $request->query('method') === 'run_rate' ? 'run_rate' : 'budget';

        return $this->successResponse($this->budgets->rollingForecast($budget, max(0, min(12, (int) $request->query('as_of_period', now()->month))), $method));
    }
}
