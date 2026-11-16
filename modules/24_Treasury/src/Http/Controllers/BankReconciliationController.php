<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Actions\ImportBankStatementAction;
use Modules\Treasury\Actions\ReconcileBankStatementAction;
use Modules\Treasury\Contracts\BankReconciliationServiceInterface;
use Modules\Treasury\Http\Requests\ImportStatementRequest;
use Modules\Treasury\Http\Requests\RunReconciliationRequest;
use Modules\Treasury\Http\Resources\BankReconciliationResource;
use Modules\Treasury\Http\Resources\BankStatementResource;
use Modules\Treasury\Models\BankReconciliation;
use OpenApi\Attributes as OA;

final class BankReconciliationController extends Controller
{
    public function __construct(
        private readonly BankReconciliationServiceInterface $reconciliationService,
        private readonly ImportBankStatementAction $importAction,
        private readonly ReconcileBankStatementAction $reconcileAction
    ) {}

    /**
     * Returns the paginated list of reconciliations of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/treasury/reconciliations',
        operationId: 'listTreasuryReconciliations',
        description: 'Returns the paginated list of reconciliations of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List reconciliations',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Reconciliations'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of reconciliations', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryBankReconciliationResource')), new OA\Property(property: 'links', ref: '#/components/schemas/TreasuryPaginatorLinks'), new OA\Property(property: 'meta', ref: '#/components/schemas/TreasuryPaginatorMeta')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $reconciliations = BankReconciliation::where('tenant_id', $tenantId)
            ->latest('period_end_date')
            ->gridPaginate((int) $request->query('per_page', 25));

        return response()->json(BankReconciliationResource::collection($reconciliations)->response()->getData(true));
    }

    /**
     * Imports a bank statement with its lines into the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/treasury/statements/import',
        operationId: 'importStatementTreasuryStatementsImport',
        description: 'Imports a bank statement with its lines into the active tenant.',
        summary: 'Import bank statement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Statements'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TreasuryImportStatementRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Import created', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryBankStatementResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function importStatement(ImportStatementRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validated();
        $data['tenant_id'] = $tenantContext->getTenantId();
        $lines = $data['lines'];
        unset($data['lines']);

        $statement = $this->importAction->execute($data, $lines);

        return response()->json(new BankStatementResource($statement), 201);
    }

    /**
     * Runs the automatic reconciliation of a bank statement.
     */
    #[OA\Post(
        path: '/api/v1/treasury/reconciliations/auto-reconcile',
        operationId: 'runAutoReconciliationTreasuryReconciliationsAutoReconcile',
        description: 'Runs the automatic reconciliation of a bank statement.',
        summary: 'Run automatic reconciliation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Reconciliations'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TreasuryRunReconciliationRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryBankReconciliationResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function runAutoReconciliation(RunReconciliationRequest $request): JsonResponse
    {
        $statementId = (string) $request->input('bank_statement_id');
        $recon = $this->reconcileAction->execute($statementId, (string) $request->user()?->id);

        return response()->json(new BankReconciliationResource($recon));
    }

    /**
     * Matches a bank statement line to an entity, with an optional allocated amount.
     */
    #[OA\Post(
        path: '/api/v1/treasury/reconciliations/lines/{lineId}/match',
        operationId: 'matchManuallyTreasuryReconciliationsLine',
        description: 'Matches a bank statement line to an entity, with an optional allocated amount.',
        summary: 'Match statement line manually',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Reconciliations'],
        parameters: [
            new OA\Parameter(name: 'lineId', in: 'path', required: true, description: 'Identifier of the line', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['bank_reconciliation_id', 'matched_entity_type', 'matched_entity_id'], properties: [new OA\Property(property: 'bank_reconciliation_id', type: 'string', format: 'uuid'), new OA\Property(property: 'matched_entity_type', type: 'string'), new OA\Property(property: 'matched_entity_id', type: 'string', format: 'uuid'), new OA\Property(property: 'allocated_amount', type: 'number', nullable: true)])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function matchManually(Request $request, string $lineId): JsonResponse
    {
        $request->validate([
            'bank_reconciliation_id' => ['required', 'uuid'],
            'matched_entity_type' => ['required', 'string'],
            'matched_entity_id' => ['required', 'uuid'],
            'allocated_amount' => ['nullable', 'numeric'],
        ]);

        $this->reconciliationService->matchManually(
            $lineId,
            $request->all(),
            (string) ($request->user()?->id ?? '')
        );

        return response()->json(['message' => 'Statement line matched successfully.']);
    }

    /**
     * Approves a bank reconciliation on behalf of the authenticated user.
     */
    #[OA\Post(
        path: '/api/v1/treasury/reconciliations/{id}/approve',
        operationId: 'approveTreasuryReconciliation',
        description: 'Approves a bank reconciliation on behalf of the authenticated user.',
        summary: 'Approve bank reconciliation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Reconciliations'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the reconciliation', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryBankReconciliationResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function approve(Request $request, string $id): JsonResponse
    {
        $recon = $this->reconciliationService->approveReconciliation(
            $id,
            (string) ($request->user()?->id ?? '')
        );

        return response()->json(new BankReconciliationResource($recon));
    }
}
