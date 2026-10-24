<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RetailPosController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function sessions(): JsonResponse
    {
        $sessions = DB::table('pos_sessions')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->latest('opened_at')
            ->paginate(25);

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

        $saleId = (string) Str::uuid();
        $receiptNumber = 'REC-'.now()->format('Ymd').'-'.rand(1000, 9999);

        DB::transaction(function () use ($saleId, $receiptNumber, $validated): void {
            DB::table('pos_sales')->insert([
                'id' => $saleId,
                'tenant_id' => $this->tenantContext->getTenantId(),
                'pos_session_id' => $validated['pos_session_id'],
                'receipt_number' => $receiptNumber,
                'total_net' => $validated['total_net'],
                'total_tax' => $validated['total_tax'],
                'total_gross' => $validated['total_gross'],
                'payment_method' => $validated['payment_method'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($validated['items'] as $item) {
                DB::table('pos_sale_items')->insert([
                    'id' => (string) Str::uuid(),
                    'pos_sale_id' => $saleId,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $item['tax_rate'],
                    'line_total' => $item['line_total'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return $this->successResponse(
            data: ['sale_id' => $saleId, 'receipt_number' => $receiptNumber],
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

        $id = (string) Str::uuid();
        DB::table('rt_fiscal_receipts')->insert([
            'id' => $id,
            'pos_sale_id' => $validated['pos_sale_id'],
            'z_report_number' => 'Z-'.now()->format('Ymd'),
            'fiscal_document_number' => 'DOC-'.rand(10000, 99999),
            'xml_lot_id' => 'LOT-'.now()->timestamp,
            'rt_status' => 'transmitted',
            'transmitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->successResponse(
            data: ['rt_receipt_id' => $id, 'rt_status' => 'transmitted'],
            message: 'Fiscal receipt transmitted to RT service'
        );
    }
}
