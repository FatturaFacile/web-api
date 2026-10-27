<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\ConvertToInvoice;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryNoteResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Responses\ApiResponse;
use App\Models\DeliveryNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DeliveryNoteController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ConvertToInvoice $toInvoice) {}

    public function index(Request $request): JsonResponse
    {
        $notes = max(5, (int) $request->query('per_page', 15))
                |> (fn ($x) => min(100, $x))
                |> DeliveryNote::with('contact')->latest('date')(...);

        return $this->successResponse(
            data: DeliveryNoteResource::collection($notes),
            message: __('sales.messages.delivery_notes_list'),
            meta: ['current_page' => $notes->currentPage(), 'last_page' => $notes->lastPage(), 'per_page' => $notes->perPage(), 'total' => $notes->total()]
        );
    }

    public function show(string $id): JsonResponse
    {
        $note = DeliveryNote::with(['contact', 'items'])->findOrFail($id);

        return $this->successResponse(new DeliveryNoteResource($note), __('sales.messages.delivery_note_retrieved'));
    }

    public function destroy(string $id): JsonResponse
    {
        DeliveryNote::findOrFail($id)->delete();

        return $this->successResponse(null, __('sales.messages.delivery_note_deleted'));
    }

    /**
     * Invoice the delivered lines of a delivery note.
     */
    public function createInvoice(string $id): JsonResponse
    {
        $invoice = $this->toInvoice->fromDeliveryNote(DeliveryNote::with('items')->findOrFail($id));

        return $this->successResponse(new InvoiceResource($invoice), __('sales.messages.delivery_note_invoiced'), Response::HTTP_CREATED);
    }
}
