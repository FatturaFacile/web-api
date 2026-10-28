<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\CustomerLedger;
use Modules\GeneralLedger\Models\SupplierLedger;

final class SubledgerController extends Controller
{
    public function customerLedger(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id ?? (string) Str::uuid7();

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
        $tenantId = $request->user()?->tenant_id ?? (string) Str::uuid7();

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
