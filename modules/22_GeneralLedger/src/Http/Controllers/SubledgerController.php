<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Models\CustomerLedger;
use Modules\GeneralLedger\Models\SupplierLedger;

final class SubledgerController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function customerLedger(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $items = CustomerLedger::where('tenant_id', $tenantId)
            ->when($request->query('customer_id'), fn ($q, $cid) => $q->where('customer_id', $cid))
            ->when($request->query('status'), fn ($q, $st) => $q->where('status', $st))
            ->orderBy('due_date')
            ->paginate((int) $request->query('per_page', 50));

        return response()->json([
            'data' => $items,
        ]);
    }

    public function supplierLedger(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $items = SupplierLedger::where('tenant_id', $tenantId)
            ->when($request->query('supplier_id'), fn ($q, $sid) => $q->where('supplier_id', $sid))
            ->when($request->query('status'), fn ($q, $st) => $q->where('status', $st))
            ->orderBy('due_date')
            ->paginate((int) $request->query('per_page', 50));

        return response()->json([
            'data' => $items,
        ]);
    }
}
