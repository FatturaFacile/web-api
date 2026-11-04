<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\SalesOrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class SalesOrderLineController extends Controller
{
    use ApiResponse;

    /**
     * Cross-order list of order lines. Filters: `open=1` (undelivered only), `contact_id`, `product_id`.
     */
    #[OA\Get(
        path: '/api/v1/sales/order-lines',
        operationId: 'getSalesOrderLines',
        description: 'Cross-order aggregate list of order lines with fulfillment tracking and open pending quantity filters.',
        summary: 'List sales order lines',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Sales', 'Orders'],
        parameters: [
            new OA\Parameter(name: 'contact_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'open', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Order lines retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $query = SalesOrderItem::query()
            ->with(['salesOrder:id,order_number,contact_id,date,status', 'salesOrder.contact:id,company_name,first_name,last_name'])
            ->whereHas('salesOrder', function ($orders) use ($request): void {
                $orders->where('status', '!=', 'cancelled');

                if ($request->filled('contact_id')) {
                    $orders->where('contact_id', $request->query('contact_id'));
                }
            })
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->query('product_id')))
            ->when($request->boolean('open'), fn ($q) => $q->whereColumn('quantity_delivered', '<', 'quantity'))
            ->latest();

        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));
        $lines = $query->paginate($perPage);

        return $this->successResponse(
            data: $lines->getCollection()->map(fn (SalesOrderItem $line): array => [
                'id' => $line->id,
                'sales_order_id' => $line->sales_order_id,
                'order_number' => $line->salesOrder->order_number,
                'order_date' => $line->salesOrder->date->toDateString(),
                'contact' => $line->salesOrder->contact?->company_name ?: trim($line->salesOrder->contact?->first_name.' '.$line->salesOrder->contact?->last_name),
                'product_id' => $line->product_id,
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'quantity_delivered' => (float) $line->quantity_delivered,
                'open_quantity' => round((float) $line->quantity - (float) $line->quantity_delivered, 4),
                'unit_price' => (float) $line->unit_price,
                'tax_rate' => (float) $line->tax_rate,
                'discount_percentage' => (float) $line->discount_percentage,
                'line_total' => (float) $line->line_total,
            ])->all(),
            message: __('sales.messages.order_lines_list'),
            meta: [
                'current_page' => $lines->currentPage(),
                'last_page' => $lines->lastPage(),
                'per_page' => $lines->perPage(),
                'total' => $lines->total(),
            ]
        );
    }
}
