<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Purchases\ReceivePassiveInvoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\UploadPassiveInvoiceRequest;
use App\Http\Resources\CatalogResource;
use App\Http\Responses\ApiResponse;
use App\Models\PassiveInvoice;
use App\Services\Sales\DocumentEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class SupplierInvoiceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ReceivePassiveInvoice $receive,
        private readonly DocumentEventRecorder $events,
    ) {}

    /**
     * List received supplier documents. Filters: `supplier_id`, `document_type`, `payment_status`.
     */
    public function index(Request $request): JsonResponse
    {
        $invoices = PassiveInvoice::query()
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->query('supplier_id')))
            ->when($request->filled('document_type'), fn ($q) => $q->where('document_type', $request->query('document_type')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->query('payment_status')))
            ->latest('date')
            ->paginate(min(100, max(5, (int) $request->query('per_page', 15))));

        return $this->successResponse(
            data: CatalogResource::collection($invoices),
            message: __('purchases.messages.supplier_invoices'),
            meta: ['current_page' => $invoices->currentPage(), 'last_page' => $invoices->lastPage(), 'per_page' => $invoices->perPage(), 'total' => $invoices->total()]
        );
    }

    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource(PassiveInvoice::findOrFail($id)), __('purchases.messages.supplier_invoice'));
    }

    /**
     * Upload a FatturaPA XML received from SDI and register its documents.
     */
    public function receiveXml(UploadPassiveInvoiceRequest $request): JsonResponse
    {
        $file = $request->file('file');

        try {
            $results = $this->receive->execute((string) file_get_contents($file->getRealPath()), $file->getClientOriginalName());
        } catch (InvalidArgumentException $e) {
            return $this->problemDetailsResponse(
                title: __('purchases.invalid_fatturapa_title'),
                detail: $e->getMessage(),
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $this->successResponse(
            array_map(fn (array $result): array => [
                'duplicate' => $result['duplicate'],
                'invoice' => (new CatalogResource($result['invoice']))->resolve($request),
            ], $results),
            __('purchases.messages.invoices_received'),
            Response::HTTP_CREATED
        );
    }

    public function markPaid(string $id): JsonResponse
    {
        $invoice = PassiveInvoice::findOrFail($id);

        if ($invoice->document_type === 'TD04') {
            throw ValidationException::withMessages(['invoice' => __('purchases.credit_note_not_payable')]);
        }

        if ($invoice->payment_status === 'paid') {
            throw ValidationException::withMessages(['invoice' => __('purchases.already_paid')]);
        }

        $invoice->update(['payment_status' => 'paid']);
        $this->events->record($invoice, 'marked_paid');

        return $this->successResponse(new CatalogResource($invoice), __('purchases.messages.marked_paid'));
    }
}
