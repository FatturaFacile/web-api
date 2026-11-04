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

final class TaxSettlementController extends Controller
{
    public function __construct(
        private readonly TaxEngineReadinessServiceInterface $taxService,
        private readonly TenantContext $tenantContext
    ) {}

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
