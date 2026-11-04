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

final class FiscalYearController extends Controller
{
    public function __construct(
        private readonly PeriodClosingServiceInterface $closingService,
        private readonly TenantContext $tenantContext
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $years = FiscalYear::where('tenant_id', $tenantId)
            ->with('periods')
            ->orderBy('year_code', 'desc')
            ->get();

        return response()->json([
            'data' => FiscalYearResource::collection($years),
        ]);
    }

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

    public function show(string $id): JsonResponse
    {
        $year = FiscalYear::with('periods')->findOrFail($id);
        $this->authorize('view', $year);

        return response()->json([
            'data' => new FiscalYearResource($year),
        ]);
    }

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
}
