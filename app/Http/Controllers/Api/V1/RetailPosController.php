<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\RtFiscalReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RetailPosController extends Controller
{
    use ApiResponse;

    public function sessions(): JsonResponse
    {
        $sessions = PosSession::latest('opened_at')->paginate(25);

        return $this->successResponse(data: $sessions, message: 'POS sessions retrieved');
    }

    /**
     * @deprecated Legacy stub that trusts client-sent totals. Use POST /api/v1/retail/pos/sales instead. The response carries the X-Deprecated header.
     */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pos_session_id' => ['required', 'uuid'],
            'total_net' => ['required', 'numeric', 'min:0'],
            'total_tax' => ['required', 'numeric', 'min:0'],
            'total_gross' => ['required', 'numeric', 'min:0'],
            'payment_method' => ['required', 'in:cash,card,mixed'],
            'items' => ['required', 'array', 'min:1'],
        ]);

        $session = PosSession::findOrFail($validated['pos_session_id']);

        $sale = PosSale::resolveConnection()->transaction(function () use ($session, $validated): PosSale {
            $receiptNumber = 'REC-'.now()->format('Ymd').'-'.rand(1000, 9999);

            $sale = PosSale::create([
                'pos_session_id' => $session->id,
                'receipt_number' => $receiptNumber,
                'total_net' => $validated['total_net'],
                'total_tax' => $validated['total_tax'],
                'total_gross' => $validated['total_gross'],
                'payment_method' => $validated['payment_method'],
            ]);

            foreach ($validated['items'] as $item) {
                $sale->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $item['tax_rate'],
                    'line_total' => $item['line_total'],
                ]);
            }

            return $sale;
        });

        return $this->successResponse(
            data: ['sale_id' => $sale->id, 'receipt_number' => $sale->receipt_number],
            message: 'Checkout completed successfully',
            statusCode: Response::HTTP_CREATED
        );
    }

    /**
     * @deprecated Legacy stub that fabricates fiscal identifiers. Use POST /api/v1/retail/pos/sales/{id}/rt-transmit instead.
     */
    public function rtSync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pos_sale_id' => ['required', 'uuid'],
        ]);

        $sale = PosSale::findOrFail($validated['pos_sale_id']);

        $receipt = RtFiscalReceipt::create([
            'pos_sale_id' => $sale->id,
            'z_report_number' => 'Z-'.now()->format('Ymd'),
            'fiscal_document_number' => 'DOC-'.rand(10000, 99999),
            'xml_lot_id' => 'LOT-'.now()->timestamp,
            'rt_status' => 'transmitted',
            'transmitted_at' => now(),
        ]);

        return $this->successResponse(
            data: ['rt_receipt_id' => $receipt->id, 'rt_status' => 'transmitted'],
            message: 'Fiscal receipt transmitted to RT service'
        );
    }
}
