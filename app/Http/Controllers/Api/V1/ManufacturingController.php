<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManufacturingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function bom(): JsonResponse
    {
        $boms = DB::table('bills_of_materials')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->whereNull('deleted_at')
            ->latest()
            ->paginate(25);

        return $this->successResponse(data: $boms, message: 'Bills of Materials (Distinta Base) retrieved');
    }

    public function productionOrders(): JsonResponse
    {
        $orders = DB::table('production_orders')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->whereNull('deleted_at')
            ->latest('start_date')
            ->paginate(25);

        return $this->successResponse(data: $orders, message: 'Production orders retrieved');
    }

    public function trackStage(Request $request, string $stageId): JsonResponse
    {
        $validated = $request->validate([
            'actual_hours' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'in:pending,running,done'],
        ]);

        DB::table('production_order_stages')
            ->where('id', $stageId)
            ->update([
                'actual_hours' => $validated['actual_hours'],
                'status' => $validated['status'],
                'updated_at' => now(),
            ]);

        return $this->successResponse(message: 'Production order stage tracked successfully');
    }
}
