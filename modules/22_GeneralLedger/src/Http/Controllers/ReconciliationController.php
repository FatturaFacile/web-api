<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Contracts\AccountingReconciliationServiceInterface;
use Modules\GeneralLedger\Http\Requests\RunReconciliationRequest;
use Modules\GeneralLedger\Http\Resources\ReconciliationRunResource;
use Modules\GeneralLedger\Models\ReconciliationRun;
use OpenApi\Attributes as OA;

final class ReconciliationController extends Controller
{
    public function __construct(
        private readonly AccountingReconciliationServiceInterface $reconciliationService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the paginated list of reconciliations of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/reconciliations',
        operationId: 'listGeneralLedgerReconciliations',
        description: 'Returns the paginated list of reconciliations of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List reconciliations',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Reconciliations'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of reconciliations', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerReconciliationRunResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $runs = ReconciliationRun::where('tenant_id', $tenantId)
            ->with('items')
            ->orderBy('created_at', 'desc')
            ->gridPaginate(min(200, max(1, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => ReconciliationRunResource::collection($runs),
        ]);
    }

    /**
     * Creates a new reconciliation in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/reconciliations',
        operationId: 'createGeneralLedgerReconciliation',
        description: 'Creates a new reconciliation in the active tenant.',
        summary: 'Create reconciliation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Reconciliations'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerRunReconciliationRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Reconciliation created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerReconciliationRunResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(RunReconciliationRequest $request): JsonResponse
    {
        $this->authorize('create', ReconciliationRun::class);

        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        $actorId = (string) $user->id;

        $type = $request->validated('reconciliation_type');
        $periodId = $request->validated('fiscal_period_id');

        if ($type === 'gr_ir') {
            $run = $this->reconciliationService->reconcileGrIr($tenantId, $periodId, $actorId);
        } else {
            $run = $this->reconciliationService->reconcileSubledger($tenantId, $type, $periodId, $actorId);
        }

        return response()->json([
            'message' => 'Reconciliation completed',
            'data' => new ReconciliationRunResource($run),
        ], 201);
    }

    /**
     * Returns a single reconciliation of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/reconciliations/{reconciliation}',
        operationId: 'getGeneralLedgerReconciliation',
        description: 'Returns a single reconciliation of the active tenant.',
        summary: 'Get reconciliation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Reconciliations'],
        parameters: [
            new OA\Parameter(name: 'reconciliation', in: 'path', required: true, description: 'Identifier of the reconciliation', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerReconciliationRunResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $run = ReconciliationRun::with('items')->findOrFail($id);
        $this->authorize('view', $run);

        return response()->json([
            'data' => new ReconciliationRunResource($run),
        ]);
    }
}
