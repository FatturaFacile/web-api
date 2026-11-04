<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\ConvertToInvoice;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryNoteResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Responses\ApiResponse;
use App\Models\DeliveryNote;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

#[OA\Tag(name: 'Delivery Notes', description: 'Transport and delivery notes (DDT) management and invoicing')]
class DeliveryNoteController extends Controller
{
    use ApiResponse;
    use AuthorizesRequests;

    public function __construct(private readonly ConvertToInvoice $toInvoice) {}

    #[OA\Get(
        path: '/api/v1/sales/delivery-notes',
        summary: 'List delivery notes',
        description: 'Get paginated list of delivery notes (DDT)',
        tags: ['Delivery Notes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Delivery notes list retrieved'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DeliveryNote::class);

        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));
        $notes = DeliveryNote::with('contact')->latest('date')->paginate($perPage);

        return $this->successResponse(
            data: DeliveryNoteResource::collection($notes),
            message: __('sales.messages.delivery_notes_list'),
            meta: ['current_page' => $notes->currentPage(), 'last_page' => $notes->lastPage(), 'per_page' => $notes->perPage(), 'total' => $notes->total()]
        );
    }

    #[OA\Get(
        path: '/api/v1/sales/delivery-notes/{id}',
        summary: 'Get delivery note',
        description: 'Retrieve details and lines of a delivery note',
        tags: ['Delivery Notes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Delivery note details retrieved'),
            new OA\Response(response: 404, description: 'Delivery note not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $note = DeliveryNote::with(['contact', 'items'])->findOrFail($id);
        $this->authorize('view', $note);

        return $this->successResponse(new DeliveryNoteResource($note), __('sales.messages.delivery_note_retrieved'));
    }

    #[OA\Delete(
        path: '/api/v1/sales/delivery-notes/{id}',
        summary: 'Delete delivery note',
        description: 'Delete a delivery note',
        tags: ['Delivery Notes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Delivery note deleted successfully'),
            new OA\Response(response: 404, description: 'Delivery note not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $note = DeliveryNote::findOrFail($id);
        $this->authorize('delete', $note);

        $note->delete();

        return $this->successResponse(null, __('sales.messages.delivery_note_deleted'));
    }

    /**
     * Invoice the delivered lines of a delivery note.
     */
    #[OA\Post(
        path: '/api/v1/sales/delivery-notes/{id}/invoice',
        summary: 'Create invoice from delivery note',
        description: 'Generate an invoice from delivered lines of a delivery note',
        tags: ['Delivery Notes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Invoice created successfully'),
            new OA\Response(response: 404, description: 'Delivery note not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function createInvoice(string $id): JsonResponse
    {
        $note = DeliveryNote::with('items')->findOrFail($id);
        $this->authorize('invoice', $note);

        $invoice = $this->toInvoice->fromDeliveryNote($note);

        return $this->successResponse(new InvoiceResource($invoice), __('sales.messages.delivery_note_invoiced'), Response::HTTP_CREATED);
    }
}
