<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Http\Requests\StoreAccrualRequest;
use Modules\GeneralLedger\Http\Resources\AccrualResource;
use Modules\GeneralLedger\Models\Accrual;
use Modules\GeneralLedger\Services\AccrualService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accruals and deferrals (ratei e risconti) with automatic reversal.
 */
final class AccrualController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AccrualService $accruals,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * The accruals and deferrals of the tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/accruals',
        operationId: 'listGeneralLedgerAccruals',
        description: 'Accruals and deferrals of the tenant, newest cut-off first, optionally of one type or one cut-off date, with the status of their entry (posted, pending_approval, reversed).',
        summary: 'List accruals and deferrals',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accruals'],
        parameters: [
            new OA\Parameter(name: 'accrual_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: Accrual::Types)),
            new OA\Parameter(name: 'cutoff_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Accruals and deferrals', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerAccrualResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'No ledger read access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $accruals = Accrual::with('journalEntry')
            ->when($request->query('accrual_type'), static fn ($query, $type) => $query->where('accrual_type', $type))
            ->when($request->query('cutoff_date'), static fn ($query, $date) => $query->whereDate('cutoff_date', $date))
            ->orderByDesc('cutoff_date')
            ->orderByDesc('created_at')
            ->get();

        return $this->successResponse(AccrualResource::collection($accruals));
    }

    /**
     * Computes and posts an accrual or deferral.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/accruals',
        operationId: 'createGeneralLedgerAccrual',
        description: 'Computes and posts an accrual or deferral at the cut-off date. The share is total_amount × days / coverage days (days counted inclusively, rounded half up to the cent): for ratei (accrued_revenue = rateo attivo, accrued_expense = rateo passivo) the days from coverage_start to the cut-off, for risconti (prepaid_expense = risconto attivo, deferred_revenue = risconto passivo) the days after the cut-off. The adjusting entry is posted on the cut-off date (Dr asset/Cr revenue, Dr expense/Cr liability, Dr asset/Cr expense, Dr revenue/Cr liability respectively), subject to the four-eyes policy, and reversed automatically on the day after. The income statement account must be revenue (for *_revenue) or expense/cogs (for *_expense), the balance sheet account an asset (rateo attivo, risconto attivo) or a liability (rateo passivo, risconto passivo).',
        summary: 'Create accrual or deferral',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accruals'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreAccrualRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Accrual posted', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerAccrualResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error, accounts not fitting the type, cut-off outside the coverage, or fiscal period of the cut-off missing or closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreAccrualRequest $request): JsonResponse
    {
        $accrual = $this->accruals->create((string) $this->tenantContext->getTenantId(), $request->validated(), (string) $request->user()->id);

        return $this->successResponse(new AccrualResource($accrual), 'Accrual posted', Response::HTTP_CREATED);
    }

    /**
     * One accrual or deferral.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/accruals/{id}',
        operationId: 'getGeneralLedgerAccrual',
        description: 'An accrual or deferral with the computation (coverage days, accrued days, amount) and its entry.',
        summary: 'Get accrual or deferral',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accruals'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Accrual', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerAccrualResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'No ledger read access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new AccrualResource(Accrual::with('journalEntry')->findOrFail($id)));
    }
}
