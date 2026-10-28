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
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class InvoiceSdiController extends Controller
{
    use ApiResponse, AuthorizesRequests;

    public function __construct(private readonly SendInvoiceToSdi $sendToSdi) {}

    /**
     * Transmit the invoice to SDI through the configured provider.
     */
    #[OA\Post(
        path: '/api/v1/sales/invoices/{id}/send-sdi',
        operationId: 'sendInvoiceToSdi',
        description: 'Transmit the invoice XML to Italian Sistema di Interscambio (SDI) through configured provider gateway.',
        summary: 'Transmit invoice to SDI',
        security: [['bearerAuth' => []]],
        tags: ['Invoices', 'SDI'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Invoice UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 202, description: 'Invoice accepted for SDI transmission', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Invoice not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 502, description: 'SDI Provider gateway error', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function send(string $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $this->authorize('sendSdi', $invoice);

        try {
            $metadata = $this->sendToSdi->execute($invoice);
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
    #[OA\Get(
        path: '/api/v1/sales/invoices/{id}/sdi-logs',
        operationId: 'getInvoiceSdiLogs',
        description: 'Outbound transmissions and inbound SDI receipts for an invoice.',
        summary: 'Get SDI transmission logs',
        security: [['bearerAuth' => []]],
        tags: ['Invoices', 'SDI'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Invoice UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'SDI logs retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Invoice not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function logs(string $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $this->authorize('view', $invoice);

        return $this->successResponse([
            'outbound' => CatalogResource::collection(EiOutboundLog::where('invoice_id', $invoice->id)->latest()->get()),
            'inbound' => CatalogResource::collection(EiInboundLog::where('invoice_id', $invoice->id)->latest()->get()),
        ], __('sdi.messages.logs'));
    }
}
