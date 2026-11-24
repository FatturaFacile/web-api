<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\PeriodClosingServiceInterface;
use Modules\GeneralLedger\Http\Requests\StoreFiscalYearRequest;
use Modules\GeneralLedger\Http\Resources\FiscalYearResource;
use Modules\GeneralLedger\Models\FiscalYear;
use OpenApi\Attributes as OA;

final class FiscalYearController extends Controller
{
    public function __construct(
        private readonly PeriodClosingServiceInterface $closingService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the list of fiscal years of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/fiscal-years',
        operationId: 'listGeneralLedgerFiscalYears',
        description: 'Returns the list of fiscal years of the active tenant.',
        summary: 'List fiscal years',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Fiscal Years'],
        responses: [
            new OA\Response(response: 200, description: 'List of fiscal years', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerFiscalYearResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $years = FiscalYear::where('tenant_id', $tenantId)
            ->with('periods')
            ->orderBy('year_code', 'desc')
            ->gridPaginate(min(1000, max(1, (int) $request->query('per_page', 1000))));

        return response()->json([
            'data' => FiscalYearResource::collection($years),
            'meta' => ['current_page' => $years->currentPage(), 'last_page' => $years->lastPage(), 'per_page' => $years->perPage(), 'total' => $years->total()],
        ]);
    }

    /**
     * Creates a new fiscal year in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/fiscal-years',
        operationId: 'createGeneralLedgerFiscalYear',
        description: 'Creates a new fiscal year in the active tenant.',
        summary: 'Create fiscal year',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Fiscal Years'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreFiscalYearRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Fiscal year created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerFiscalYearResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreFiscalYearRequest $request): JsonResponse
    {
        $this->authorize('create', FiscalYear::class);

        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $year = FiscalYear::create(array_merge($request->validated(), [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'status' => 'open',
        ]));

        return response()->json([
            'message' => 'Fiscal Year created successfully',
            'data' => new FiscalYearResource($year),
        ], 201);
    }

    /**
     * Returns a single fiscal year of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/fiscal-years/{fiscal_year}',
        operationId: 'getGeneralLedgerFiscalYear',
        description: 'Returns a single fiscal year of the active tenant.',
        summary: 'Get fiscal year',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Fiscal Years'],
        parameters: [
            new OA\Parameter(name: 'fiscal_year', in: 'path', required: true, description: 'Identifier of the fiscal year', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerFiscalYearResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $year = FiscalYear::with('periods')->findOrFail($id);
        $this->authorize('view', $year);

        return response()->json([
            'data' => new FiscalYearResource($year),
        ]);
    }

    /**
     * Runs the closing of the fiscal year and returns the closing run.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/fiscal-years/{id}/close',
        operationId: 'closeYearGeneralLedgerFiscalYear',
        description: 'Runs the closing of the fiscal year and returns the closing run.',
        summary: 'Close fiscal year',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Fiscal Years'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the fiscal year', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerFinancialClosingRunModel')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function closeYear(Request $request, string $id): JsonResponse
    {
        $year = FiscalYear::findOrFail($id);
        $this->authorize('update', $year);

        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        $actorId = (string) $user->id;
        $run = $this->closingService->closeFiscalYear($id, $actorId);

        return response()->json([
            'message' => 'Fiscal Year closed successfully',
            'data' => $run,
        ]);
    }

    /**
     * Reopens a closed fiscal year prior to AGM approval (CO Art. 958 / 698).
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/fiscal-years/{id}/reopen',
        operationId: 'reopenYearGeneralLedgerFiscalYear',
        description: 'Reopens a closed fiscal year prior to AGM approval and reverses the closing journal entries.',
        summary: 'Reopen fiscal year',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Fiscal Years'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the fiscal year', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', maxLength: 500),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Fiscal year reopened successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerFiscalYearResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Cannot reopen fiscal year', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function reopenYear(Request $request, string $id): JsonResponse
    {
        $year = FiscalYear::findOrFail($id);
        $this->authorize('update', $year);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $user = $request->user();
        if ($user === null) {
            abort(401, 'Unauthenticated');
        }
        $actorId = (string) $user->id;
        $reopened = $this->closingService->reopenFiscalYear($id, $actorId, (string) $validated['reason']);

        return response()->json([
            'message' => 'Fiscal Year reopened successfully',
            'data' => new FiscalYearResource($reopened),
        ]);
    }

    /**
     * Locks the fiscal year upon Annual General Meeting (AGM) approval (CO Art. 698 / 958).
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/fiscal-years/{id}/approve-agm',
        operationId: 'approveAgmGeneralLedgerFiscalYear',
        description: 'Records the Annual General Meeting (AGM) resolution approving the closed financial statements, permanently locking the fiscal year against reopening.',
        summary: 'Approve fiscal year at AGM',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Fiscal Years'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the fiscal year', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'resolution_reference', type: 'string', maxLength: 190),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'AGM approval recorded successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerFiscalYearResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Fiscal year must be closed before AGM approval', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function approveAgm(Request $request, string $id): JsonResponse
    {
        $year = FiscalYear::findOrFail($id);
        $this->authorize('update', $year);

        $validated = $request->validate([
            'resolution_reference' => ['nullable', 'string', 'max:190'],
        ]);

        $user = $request->user();
        if ($user === null) {
            abort(401, 'Unauthenticated');
        }
        $actorId = (string) $user->id;
        $approved = $this->closingService->approveFiscalYearAtAgm(
            $id,
            $actorId,
            isset($validated['resolution_reference']) ? (string) $validated['resolution_reference'] : null
        );

        return response()->json([
            'message' => 'Fiscal Year approved at AGM and permanently locked',
            'data' => new FiscalYearResource($approved),
        ]);
    }
}
