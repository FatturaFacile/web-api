<?php

declare(strict_types=1);

namespace Modules\EDocumentNetworks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Modules\EDocumentNetworks\Services\Peppol\PeppolBillingBuilder;
use OpenApi\Attributes as OA;

/**
 * HTTP endpoints of the Peppol BIS Billing 3.0 documents of the EDocumentNetworks module.
 */
final class PeppolInvoiceController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly PeppolBillingBuilder $builder) {}

    /**
     * Download the Peppol UBL of an invoice.
     */
    #[OA\Get(
        path: '/api/v1/edocument-networks/peppol/invoices/{id}/ubl',
        operationId: 'downloadPeppolInvoiceUbl',
        description: 'The Peppol BIS Billing 3.0 document (UBL 2.1) of a sales invoice (type code 380) or credit note (381), built from the lines with exact decimals so the totals reconcile. It only builds the file: it is not transmitted to any Access Point. 422 names the master data Peppol requires and the records lack.',
        summary: 'Download the Peppol UBL of an invoice',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Networks'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The UBL 2.1 XML file', content: new OA\MediaType(mediaType: 'application/xml', schema: new OA\Schema(type: 'string'))),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Invoice not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Master data missing for the Peppol document', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
        ]
    )]
    public function ubl(string $id): Response|JsonResponse
    {
        $invoice = Invoice::query()->with(['items', 'contact', 'tenant'])->findOrFail($id);

        try {
            $xml = $this->builder->build($invoice);
        } catch (InvalidArgumentException $exception) {
            return $this->problemDetailsResponse('Peppol document not buildable', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $fileName = preg_replace('/[^A-Za-z0-9._-]+/', '_', 'peppol_'.$invoice->invoice_number).'.xml';

        return response($xml, Response::HTTP_OK, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $fileName),
        ]);
    }
}
