<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\QrBillGenerationException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Services\SwissQrBill\SwissQrBillService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class InvoiceQrBillController extends Controller
{
    use ApiResponse;

    /**
     * The Swiss QR-bill of an invoice: SPC payload, QR code images and the reference data of the payment slip.
     */
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
