<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Ticket;
use App\Models\WorkOrder;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

#[OA\Tag(name: 'Field Service', description: 'Field service management, tickets, and work orders')]
class FieldServiceController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/field-service/tickets',
        summary: 'List field service tickets',
        description: 'Get paginated list of field service tickets',
        tags: ['Field Service'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Tickets retrieved'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function tickets(): JsonResponse
    {
        $tickets = Ticket::latest()->paginate(RequestedPerPage::resolve(25));

        return $this->successResponse(data: $tickets, message: 'Tickets retrieved');
    }

    #[OA\Get(
        path: '/api/v1/field-service/work-orders',
        summary: 'List work orders',
        description: 'Get paginated list of work orders (rapportini)',
        tags: ['Field Service'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Work orders retrieved'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function workOrders(): JsonResponse
    {
        $orders = WorkOrder::latest('date')->paginate(RequestedPerPage::resolve(25));

        return $this->successResponse(data: $orders, message: 'Work orders (Rapportini) retrieved');
    }

    /**
     * Store digital client signature on work order (rapportino).
     *
     * @deprecated Legacy stub that stores no image and skips status checks. Use POST /api/v1/field-service/work-orders/{id}/signature instead.
     */
    #[OA\Post(
        path: '/api/v1/field-service/work-orders/{id}/sign',
        summary: 'Sign work order (legacy)',
        description: 'Store digital client signature on a work order',
        deprecated: true,
        tags: ['Field Service'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['client_signed_by', 'signature_base64'],
                properties: [
                    new OA\Property(property: 'client_signed_by', type: 'string'),
                    new OA\Property(property: 'signature_base64', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Work order signed successfully'),
            new OA\Response(response: 404, description: 'Work order not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
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
