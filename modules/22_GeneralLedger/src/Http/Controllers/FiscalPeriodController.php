<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\PeriodClosingServiceInterface;
use Modules\GeneralLedger\Http\Requests\StoreFiscalPeriodRequest;
use Modules\GeneralLedger\Http\Resources\FiscalPeriodResource;
use Modules\GeneralLedger\Models\FiscalPeriod;

final class FiscalPeriodController extends Controller
{
    public function __construct(
        private readonly PeriodClosingServiceInterface $closingService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id ?? (string) Str::uuid7();

        $periods = FiscalPeriod::where('tenant_id', $tenantId)
            ->when($request->query('fiscal_year_id'), fn ($q, $fyId) => $q->where('fiscal_year_id', $fyId))
            ->orderBy('period_number')
            ->get();

        return response()->json([
            'data' => FiscalPeriodResource::collection($periods),
        ]);
    }

    public function store(StoreFiscalPeriodRequest $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id ?? (string) Str::uuid7();

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

    public function show(string $id): JsonResponse
    {
        $period = FiscalPeriod::findOrFail($id);

        return response()->json([
            'data' => new FiscalPeriodResource($period),
        ]);
    }

    public function closePeriod(Request $request, string $id): JsonResponse
    {
        $actorId = (string) ($request->user()?->id ?? Str::uuid7());
        $period = $this->closingService->closePeriod($id, $actorId);

        return response()->json([
            'message' => 'Fiscal Period closed successfully',
            'data' => new FiscalPeriodResource($period),
        ]);
    }

    public function reopenPeriod(Request $request, string $id): JsonResponse
    {
        $actorId = (string) ($request->user()?->id ?? Str::uuid7());
        $period = $this->closingService->reopenPeriod($id, $actorId);

        return response()->json([
            'message' => 'Fiscal Period reopened successfully',
            'data' => new FiscalPeriodResource($period),
        ]);
    }
}
