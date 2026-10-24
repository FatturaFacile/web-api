<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sdi\SendInvoiceToSdi;
use App\Exceptions\SdiProviderException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogResource;
use App\Http\Responses\ApiResponse;
use App\Models\EiInboundLog;
use App\Models\EiOutboundLog;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class InvoiceSdiController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SendInvoiceToSdi $sendToSdi) {}

    /**
     * Transmit the invoice to SDI through the configured provider.
     */
    public function send(string $id): JsonResponse
    {
        try {
            $metadata = $this->sendToSdi->execute(Invoice::findOrFail($id));
        } catch (SdiProviderException $e) {
            return $this->problemDetailsResponse(
                title: __('sdi.send_failed_title'),
                detail: $e->getMessage(),
                status: Response::HTTP_BAD_GATEWAY
            );
        }

        return $this->successResponse(
            collect($metadata->attributesToArray())->except(['xml_content'])->all(),
            __('sdi.messages.sent'),
            Response::HTTP_ACCEPTED
        );
    }

    /**
     * Outbound transmissions and inbound SDI receipts for an invoice.
     */
    public function logs(string $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        return $this->successResponse([
            'outbound' => CatalogResource::collection(EiOutboundLog::where('invoice_id', $invoice->id)->latest()->get()),
            'inbound' => CatalogResource::collection(EiInboundLog::where('invoice_id', $invoice->id)->latest()->get()),
        ], __('sdi.messages.logs'));
    }
}
