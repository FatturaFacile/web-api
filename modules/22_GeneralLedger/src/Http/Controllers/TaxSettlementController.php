<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Contracts\TaxEngineReadinessServiceInterface;
use Modules\GeneralLedger\Http\Resources\JournalEntryResource;
use Modules\GeneralLedger\Http\Resources\VatSettlementResource;
use Modules\GeneralLedger\Models\VatSettlement;
use OpenApi\Attributes as OA;

final class TaxSettlementController extends Controller
{
    public function __construct(
        private readonly TaxEngineReadinessServiceInterface $taxService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns a VAT settlement of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/tax-settlements/{id}',
        operationId: 'getGeneralLedgerTaxSettlement',
        description: 'Returns a VAT settlement of the active tenant.',
        summary: 'Get VAT settlement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the tax settlement', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerVatSettlementResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? request()->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $settlement = VatSettlement::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        return response()->json([
            'data' => new VatSettlementResource($settlement),
        ]);
    }

    /**
     * Calculates the VAT settlement of the fiscal period.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/tax-settlements/calculate',
        operationId: 'calculateGeneralLedgerTaxSettlement',
        description: 'Calculates the VAT settlement of the fiscal period.',
        summary: 'Calculate VAT settlement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'fiscal_period_id', type: 'string')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerVatSettlementResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function calculate(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        $actorId = (string) $user->id;
        $fiscalPeriodId = (string) $request->input('fiscal_period_id');

        $settlement = $this->taxService->calculateVatSettlement($tenantId, $fiscalPeriodId, $actorId);

        return response()->json([
            'message' => 'VAT settlement calculated successfully',
            'data' => new VatSettlementResource($settlement),
        ]);
    }

    /**
     * Posts the journal entry of a VAT settlement and returns it.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/tax-settlements/{id}/post',
        operationId: 'postGeneralLedgerTaxSettlement',
        description: 'Posts the journal entry of a VAT settlement and returns it.',
        summary: 'Post VAT settlement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the tax settlement', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerJournalEntryResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function post(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        VatSettlement::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $actorId = (string) $user->id;
        $journal = $this->taxService->postVatSettlement($id, $actorId);

        return response()->json([
            'message' => 'VAT settlement journal posted successfully',
            'data' => new JournalEntryResource($journal),
        ]);
    }
}
