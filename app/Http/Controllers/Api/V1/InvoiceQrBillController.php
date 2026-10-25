<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\QrBillGenerationException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Services\SwissQrBill\SwissQrBillService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class InvoiceQrBillController extends Controller
{
    use ApiResponse;

    /**
     * The Swiss QR-bill of an invoice: SPC payload, QR code images and the reference data of the payment slip.
     */
    #[OA\Get(
        path: '/api/v1/sales/invoices/{id}/qr-bill',
        operationId: 'getInvoiceQrBill',
        description: 'The Swiss QR-bill of an invoice: SPC payload, QR code images and the reference data of the payment slip.',
        summary: 'Get invoice Swiss QR-Bill',
        security: [['bearerAuth' => []]],
        tags: ['Invoices', 'Swiss QR'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'Unique UUID of the invoice',
                schema: new OA\Schema(type: 'string', format: 'uuid', example: 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Swiss QR-bill payload retrieved successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 404,
                description: 'Invoice not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 422,
                description: 'Validation or QR generation error',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')
            ),
        ]
    )]
    public function show(SwissQrBillService $qrBills, string $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        try {
            $bill = $qrBills->generate($invoice);
        } catch (QrBillGenerationException $e) {
            return $this->problemDetailsResponse(
                title: __('qr_bill.problem_title'),
                detail: $e->getMessage(),
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                invalidParams: array_map(fn (string $violation): array => ['reason' => $violation], $e->violations)
            );
        }

        return $this->successResponse([
            'payload' => $bill->payload,
            'svg' => $bill->svg,
            'png' => $bill->pngDataUri,
            'reference_type' => $bill->referenceType,
            'reference' => $bill->reference,
            'iban' => $bill->iban,
            'currency' => $bill->currency,
            'amount' => $bill->amount,
        ], __('qr_bill.messages.retrieved'));
    }
}
