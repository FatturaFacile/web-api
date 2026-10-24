<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\ConvertToInvoice;
use App\Actions\Sales\RecordPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StorePaymentRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class InvoicePaymentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly RecordPayment $recordPayment,
        private readonly ConvertToInvoice $toInvoice,
    ) {}

    public function index(string $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        return $this->successResponse(
            PaymentResource::collection($invoice->payments()->orderBy('paid_at')->get()),
            __('sales.messages.payments_list')
        );
    }

    public function store(StorePaymentRequest $request, string $id): JsonResponse
    {
        $payment = $this->recordPayment->execute(Invoice::findOrFail($id), $request->validated());

        return $this->successResponse(new PaymentResource($payment), __('sales.messages.payment_recorded'), Response::HTTP_CREATED);
    }

    /**
     * Convert a proforma invoice into a standard numbered invoice.
     */
    public function convertProforma(string $id): JsonResponse
    {
        $invoice = $this->toInvoice->fromProforma(Invoice::with('items')->findOrFail($id));

        return $this->successResponse(new InvoiceResource($invoice), __('sales.messages.proforma_converted'), Response::HTTP_CREATED);
    }
}
