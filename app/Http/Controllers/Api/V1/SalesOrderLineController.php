<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\SalesOrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesOrderLineController extends Controller
{
    use ApiResponse;

    /**
     * Cross-order list of order lines. Filters: `open=1` (undelivered only), `contact_id`, `product_id`.
     */
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
            ])->all(),
            message: __('sales.messages.order_lines'),
            meta: ['current_page' => $lines->currentPage(), 'last_page' => $lines->lastPage(), 'per_page' => $lines->perPage(), 'total' => $lines->total()]
        );
    }
}
