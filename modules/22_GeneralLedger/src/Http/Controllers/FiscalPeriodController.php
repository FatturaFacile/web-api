<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\PeriodClosingServiceInterface;
use Modules\GeneralLedger\Http\Requests\StoreFiscalPeriodRequest;
use Modules\GeneralLedger\Http\Resources\FiscalPeriodResource;
use Modules\GeneralLedger\Models\FiscalPeriod;
use OpenApi\Attributes as OA;

final class FiscalPeriodController extends Controller
{
    public function __construct(
        private readonly PeriodClosingServiceInterface $closingService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the list of fiscal periods of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/fiscal-periods',
        operationId: 'listGeneralLedgerFiscalPeriods',
        description: 'Returns the list of fiscal periods of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List fiscal periods',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Fiscal Periods'],
        parameters: [
            new OA\Parameter(name: 'fiscal_year_id', in: 'query', required: false, description: 'Filter by fiscal year', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of fiscal periods', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerFiscalPeriodResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $periods = FiscalPeriod::where('tenant_id', $tenantId)
            ->when($request->query('fiscal_year_id'), fn ($q, $fyId) => $q->where('fiscal_year_id', $fyId))
            ->orderBy('period_number')
            ->get();

        return response()->json([
            'data' => FiscalPeriodResource::collection($periods),
        ]);
    }

    /**
     * Creates a new fiscal period in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/fiscal-periods',
        operationId: 'createGeneralLedgerFiscalPeriod',
        description: 'Creates a new fiscal period in the active tenant.',
        summary: 'Create fiscal period',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Fiscal Periods'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreFiscalPeriodRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Fiscal period created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerFiscalPeriodResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreFiscalPeriodRequest $request): JsonResponse
    {
        $this->authorize('create', FiscalPeriod::class);

        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $period = FiscalPeriod::create(array_merge($request->validated(), [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'status' => 'open',
        ]));

        return response()->json([
            'message' => 'Fiscal Period created successfully',
            'data' => new FiscalPeriodResource($period),
        ], 201);
    }

    /**
     * Returns a single fiscal period of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/fiscal-periods/{fiscal_period}',
        operationId: 'getGeneralLedgerFiscalPeriod',
        description: 'Returns a single fiscal period of the active tenant.',
        summary: 'Get fiscal period',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Fiscal Periods'],
        parameters: [
            new OA\Parameter(name: 'fiscal_period', in: 'path', required: true, description: 'Identifier of the fiscal period', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerFiscalPeriodResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $period = FiscalPeriod::findOrFail($id);
        $this->authorize('view', $period);

        return response()->json([
            'data' => new FiscalPeriodResource($period),
        ]);
    }

    /**
     * Closes the fiscal period so that no further journal can be posted to it.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/fiscal-periods/{id}/close',
        operationId: 'closePeriodGeneralLedgerFiscalPeriod',
        description: 'Closes the fiscal period so that no further journal can be posted to it.',
        summary: 'Close fiscal period',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Fiscal Periods'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the fiscal period', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerFiscalPeriodResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function closePeriod(Request $request, string $id): JsonResponse
    {
        $period = FiscalPeriod::findOrFail($id);
        $this->authorize('update', $period);

        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        $actorId = (string) $user->id;
        $closed = $this->closingService->closePeriod($id, $actorId);

        return response()->json([
            'message' => 'Fiscal Period closed successfully',
            'data' => new FiscalPeriodResource($closed),
        ]);
    }

    /**
     * Reopens a closed fiscal period.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/fiscal-periods/{id}/reopen',
        operationId: 'reopenPeriodGeneralLedgerFiscalPeriod',
        description: 'Reopens a closed fiscal period.',
        summary: 'Reopen fiscal period',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Fiscal Periods'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the fiscal period', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerFiscalPeriodResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function reopenPeriod(Request $request, string $id): JsonResponse
    {
        $period = FiscalPeriod::findOrFail($id);
        $this->authorize('update', $period);

        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        $actorId = (string) $user->id;
        $reopened = $this->closingService->reopenPeriod($id, $actorId);

        return response()->json([
            'message' => 'Fiscal Period reopened successfully',
            'data' => new FiscalPeriodResource($reopened),
        ]);
    }
}
