<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\BillOfMaterial;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManufacturingController extends Controller
{
    use ApiResponse;

    public function bom(): JsonResponse
    {
        $boms = BillOfMaterial::latest()->paginate(25);

        return $this->successResponse(data: $boms, message: 'Bills of Materials (Distinta Base) retrieved');
    }

    public function productionOrders(): JsonResponse
    {
        $orders = ProductionOrder::latest('start_date')->paginate(25);

        return $this->successResponse(data: $orders, message: 'Production orders retrieved');
    }

    public function trackStage(Request $request, string $stageId): JsonResponse
    {
        $validated = $request->validate([
            'actual_hours' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'in:pending,running,done'],
        ]);

        $stage = ProductionOrderStage::whereHas('productionOrder')->findOrFail($stageId);
        $stage->update($validated);

        return $this->successResponse(message: 'Production order stage tracked successfully');
    }
}
