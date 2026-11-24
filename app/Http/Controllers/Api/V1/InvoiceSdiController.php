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
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
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

        app(AuditLedgerInterface::class)->record(
            action: 'SDI_SENT',
            model: $invoice,
            payloadBefore: ['status' => $invoice->getOriginal('status')],
            payloadAfter: collect($metadata->attributesToArray())->except(['xml_content'])->all(),
            moduleCode: 'INVOICING'
        );

        return $this->successResponse(
            collect($metadata->attributesToArray())->except(['xml_content'])->all(),
            __('sdi.messages.sent'),
            Response::HTTP_ACCEPTED
        );
    }

    /**
     * Transmit several invoices to SDI. One failure does not stop the others: the outcome is reported per invoice.
     */
    #[OA\Post(
        path: '/api/v1/sales/invoices/send-sdi-bulk',
        operationId: 'sendInvoicesToSdiBulk',
        description: 'Transmits up to 100 invoices to SDI, one at a time. The response is always 200 and states for each invoice whether it was sent, with the validation or provider errors of the ones that were not.',
        summary: 'Transmit invoices to SDI in bulk',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['invoice_ids'], properties: [
            new OA\Property(property: 'invoice_ids', type: 'array', minItems: 1, maxItems: 100, items: new OA\Items(type: 'string', format: 'uuid')),
        ])),
        tags: ['Invoices', 'SDI'],
        responses: [
            new OA\Response(response: 200, description: 'Outcome per invoice', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'sent', type: 'integer'),
                    new OA\Property(property: 'failed', type: 'integer'),
                    new OA\Property(property: 'results', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'invoice_number', type: 'string', nullable: true),
                        new OA\Property(property: 'status', type: 'string', enum: ['sent', 'failed']),
                        new OA\Property(property: 'sdi_status', type: 'string', nullable: true),
                        new OA\Property(property: 'errors', type: 'array', items: new OA\Items(type: 'string')),
                    ], type: 'object')),
                ]),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function sendBulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1', 'max:100'],
            'invoice_ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        $results = array_map(fn (string $id): array => $this->sendOne($request, $id), $validated['invoice_ids']);
        $sent = count(array_filter($results, static fn (array $result): bool => $result['status'] === 'sent'));

        return $this->successResponse(
            ['sent' => $sent, 'failed' => count($results) - $sent, 'results' => $results],
            __('sdi.messages.bulk_sent')
        );
    }

    /**
     * @return array{invoice_id: string, invoice_number: ?string, status: string, sdi_status: ?string, errors: list<string>}
     */
    private function sendOne(Request $request, string $id): array
    {
        $invoice = Invoice::find($id);
        $outcome = ['invoice_id' => $id, 'invoice_number' => $invoice?->invoice_number, 'status' => 'failed', 'sdi_status' => null, 'errors' => []];

        if ($invoice === null) {
            return [...$outcome, 'errors' => [__('sdi.bulk.not_found')]];
        }

        if ($request->user()->cannot('sendSdi', $invoice)) {
            return [...$outcome, 'errors' => [__('sdi.bulk.forbidden')]];
        }

        try {
            $metadata = $this->sendToSdi->execute($invoice);
        } catch (ValidationException $e) {
            return [...$outcome, 'errors' => array_values(Arr::flatten($e->errors()))];
        } catch (SdiProviderException|InvalidArgumentException $e) {
            return [...$outcome, 'sdi_status' => $invoice->sdiMetadata()->value('sdi_status'), 'errors' => [$e->getMessage()]];
        }

        app(AuditLedgerInterface::class)->record(
            action: 'SDI_SENT',
            model: $invoice,
            payloadBefore: ['status' => $invoice->getOriginal('status')],
            payloadAfter: collect($metadata->attributesToArray())->except(['xml_content'])->all(),
            moduleCode: 'INVOICING'
        );

        return [...$outcome, 'status' => 'sent', 'sdi_status' => $metadata->sdi_status];
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
