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

final class ReconciliationController extends Controller
{
    public function __construct(
        private readonly AccountingReconciliationServiceInterface $reconciliationService,
        private readonly TenantContext $tenantContext
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $runs = ReconciliationRun::where('tenant_id', $tenantId)
            ->with('items')
            ->orderBy('created_at', 'desc')
            ->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'data' => ReconciliationRunResource::collection($runs),
        ]);
    }

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

    public function show(string $id): JsonResponse
    {
        $run = ReconciliationRun::with('items')->findOrFail($id);
        $this->authorize('view', $run);

        return response()->json([
            'data' => new ReconciliationRunResource($run),
        ]);
    }
}
