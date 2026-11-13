<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Http\RequestedPerPage;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\StockReservationServiceInterface;
use Modules\Inventory\DTOs\StockReservationDTO;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\PortalMovementRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Portal Requests', description: 'Movement requests sent by customers from the portal')]
final class PortalMovementRequestController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/portal-requests',
        summary: 'List portal movement requests',
        tags: ['Inventory Portal Requests'],
        parameters: [new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'rejected']))],
        responses: [
            new OA\Response(response: 200, description: 'Requests', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PortalMovementRequest'))])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List portal movement requests', tags: ['Inventory'])]
    public function index(Request $request): JsonResponse
    {
        return response()->json(PortalMovementRequest::query()
            ->when($request->filled('status'), static fn ($query) => $query->where('status', $request->query('status')))
            ->orderBy('created_at')
            ->paginate(RequestedPerPage::resolve(25)));
    }

    #[OA\Post(
        path: '/api/v1/inventory/portal-requests/{id}/approve',
        summary: 'Approve a movement request',
        description: 'A withdrawal or transfer reserves the stock for the customer; a return waits for the goods and reserves nothing.',
        tags: ['Inventory Portal Requests'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'decision_note', type: 'string')])),
        responses: [
            new OA\Response(response: 200, description: 'Request approved', content: new OA\JsonContent(ref: '#/components/schemas/PortalMovementRequest')),
            new OA\Response(response: 404, description: 'Request not found'),
            new OA\Response(response: 422, description: 'Already decided, or not enough stock'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Approve a movement request', tags: ['Inventory'])]
    public function approve(string $id, Request $request, StockReservationServiceInterface $reservations): JsonResponse
    {
        $note = $request->validate(['decision_note' => ['nullable', 'string', 'max:1000']])['decision_note'] ?? null;

        $decided = DB::transaction(function () use ($id, $note, $reservations): PortalMovementRequest {
            $movementRequest = $this->pending($id);
            $reservationId = null;

            if ($movementRequest->request_type !== 'return') {
                $reservationId = $reservations->allocate(new StockReservationDTO(
                    stockItemId: $movementRequest->stock_item_id,
                    warehouseId: $movementRequest->warehouse_id,
                    reservedForType: 'portal_movement_requests',
                    reservedForId: $movementRequest->id,
                    quantity: (float) $movementRequest->quantity,
                    userId: (string) auth()->id(),
                    lotId: $movementRequest->lot_id
                ))->id;
            }

            $movementRequest->update(['status' => 'approved', 'decided_by' => auth()->id(), 'decided_at' => now(), 'decision_note' => $note, 'stock_reservation_id' => $reservationId]);

            return $movementRequest;
        });

        return response()->json($decided);
    }

    #[OA\Post(
        path: '/api/v1/inventory/portal-requests/{id}/reject',
        summary: 'Reject a movement request',
        tags: ['Inventory Portal Requests'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'decision_note', type: 'string')])),
        responses: [
            new OA\Response(response: 200, description: 'Request rejected', content: new OA\JsonContent(ref: '#/components/schemas/PortalMovementRequest')),
            new OA\Response(response: 404, description: 'Request not found'),
            new OA\Response(response: 422, description: 'Already decided'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Reject a movement request', tags: ['Inventory'])]
    public function reject(string $id, Request $request): JsonResponse
    {
        $note = $request->validate(['decision_note' => ['nullable', 'string', 'max:1000']])['decision_note'] ?? null;

        $decided = DB::transaction(function () use ($id, $note): PortalMovementRequest {
            $movementRequest = $this->pending($id);
            $movementRequest->update(['status' => 'rejected', 'decided_by' => auth()->id(), 'decided_at' => now(), 'decision_note' => $note]);

            return $movementRequest;
        });

        return response()->json($decided);
    }

    private function pending(string $id): PortalMovementRequest
    {
        $movementRequest = PortalMovementRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        if ($movementRequest->status !== 'pending') {
            throw new StockRuleViolation('This request was already decided.');
        }

        return $movementRequest;
    }
}
