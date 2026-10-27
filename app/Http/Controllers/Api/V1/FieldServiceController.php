<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Ticket;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FieldServiceController extends Controller
{
    use ApiResponse;

    public function tickets(): JsonResponse
    {
        $tickets = Ticket::latest()->paginate(25);

        return $this->successResponse(data: $tickets, message: 'Tickets retrieved');
    }

    public function workOrders(): JsonResponse
    {
        $orders = WorkOrder::latest('date')->paginate(25);

        return $this->successResponse(data: $orders, message: 'Work orders (Rapportini) retrieved');
    }

    /**
     * Store digital client signature on work order (rapportino).
     *
     * @deprecated Legacy stub that stores no image and skips status checks. Use POST /api/v1/field-service/work-orders/{id}/signature instead.
     */
    public function signWorkOrder(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'client_signed_by' => ['required', 'string', 'max:100'],
            'signature_base64' => ['required', 'string'],
        ]);

        $order = WorkOrder::find($id);

        if (! $order) {
            return $this->problemDetailsResponse('Not Found', 'Work order not found', Response::HTTP_NOT_FOUND);
        }

        $order->update([
            'client_signed_by' => $validated['client_signed_by'],
            'client_signature_path' => 'signatures/wo-'.$id.'.png',
            'signed_at' => now(),
            'status' => 'signed',
        ]);

        return $this->successResponse(
            data: ['id' => $id, 'status' => 'signed'],
            message: 'Work order signed successfully'
        );
    }
}
