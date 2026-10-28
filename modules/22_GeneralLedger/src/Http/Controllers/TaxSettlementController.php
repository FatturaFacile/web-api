<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\TaxEngineReadinessServiceInterface;
use Modules\GeneralLedger\Http\Resources\JournalEntryResource;
use Modules\GeneralLedger\Http\Resources\VatSettlementResource;
use Modules\GeneralLedger\Models\VatSettlement;

final class TaxSettlementController extends Controller
{
    public function __construct(
        private readonly TaxEngineReadinessServiceInterface $taxService
    ) {}

    public function show(string $id): JsonResponse
    {
        $settlement = VatSettlement::findOrFail($id);

        return response()->json([
            'data' => new VatSettlementResource($settlement),
        ]);
    }

    public function calculate(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id ?? (string) Str::uuid7();
        $actorId = (string) ($request->user()?->id ?? Str::uuid7());
        $fiscalPeriodId = (string) $request->input('fiscal_period_id');

        $settlement = $this->taxService->calculateVatSettlement($tenantId, $fiscalPeriodId, $actorId);

        return response()->json([
            'message' => 'VAT settlement calculated successfully',
            'data' => new VatSettlementResource($settlement),
        ]);
    }

    public function post(Request $request, string $id): JsonResponse
    {
        $actorId = (string) ($request->user()?->id ?? Str::uuid7());
        $journal = $this->taxService->postVatSettlement($id, $actorId);

        return response()->json([
            'message' => 'VAT settlement journal posted successfully',
            'data' => new JournalEntryResource($journal),
        ]);
    }
}
