<?php

declare(strict_types=1);

namespace Modules\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Models\Warehouse;
use Modules\Invoicing\Models\SalesOrder;
use OpenApi\Attributes as OA;

/**
 * Small tenant-scoped lookups feeding the DDT wizard, transfer form and consignment screens.
 */
#[OA\Tag(name: 'Logistics Lookups', description: 'Warehouses, products, customers and open sales orders for the logistics screens')]
final class LogisticsLookupController extends Controller
{
    #[OA\Get(path: '/api/v1/logistics/lookups/warehouses', summary: 'Warehouses with type and legal owner', description: 'Requires permission delivery_notes.view.', tags: ['Logistics Lookups'], responses: [new OA\Response(response: 200, description: 'Success')])]
    public function warehouses(Request $request): JsonResponse
    {
        $rows = Warehouse::query()
            ->when($request->query('warehouse_type'), fn ($q, $v) => $q->where('warehouse_type', $v))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'warehouse_type', 'legal_owner_type', 'linked_partner_id', 'company_entity_id']);

        return response()->json(['data' => $rows]);
    }

    #[OA\Get(path: '/api/v1/logistics/lookups/products', summary: 'Products (search by SKU or name)', description: 'Requires permission delivery_notes.view.', tags: ['Logistics Lookups'], responses: [new OA\Response(response: 200, description: 'Success')])]
    public function products(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $rows = Product::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('sku', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('sku')
            ->limit(50)
            ->get(['id', 'sku', 'name', 'unit_measure', 'cost_price', 'sale_price', 'tax_rate']);

        return response()->json(['data' => $rows]);
    }

    #[OA\Get(path: '/api/v1/logistics/lookups/partners', summary: 'Customers / suppliers (search by name)', description: 'Requires permission delivery_notes.view.', tags: ['Logistics Lookups'], responses: [new OA\Response(response: 200, description: 'Success')])]
    public function partners(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $rows = Contact::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('company_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")))
            ->orderBy('company_name')
            ->limit(50)
            ->get(['id', 'company_name', 'first_name', 'last_name', 'country', 'type']);

        return response()->json(['data' => $rows]);
    }

    #[OA\Get(path: '/api/v1/logistics/lookups/open-orders', summary: 'Sales orders with lines still to be shipped', description: 'Requires permission delivery_notes.create.', tags: ['Logistics Lookups'], responses: [new OA\Response(response: 200, description: 'Success')])]
    public function openOrders(Request $request): JsonResponse
    {
        $orders = SalesOrder::query()
            ->with('items')
            ->whereNotIn('status', ['cancelled'])
            ->where(fn ($q) => $q->whereNull('fulfillment_status')->orWhere('fulfillment_status', '!=', 'fulfilled'))
            ->when($request->query('contact_id'), fn ($q, $v) => $q->where('contact_id', $v))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $data = [];
        foreach ($orders as $order) {
            $items = [];
            foreach ($order->items as $item) {
                $remaining = round((float) $item->quantity - (float) $item->quantity_delivered, 4);
                if ($remaining > 0) {
                    $items[] = [
                        'id' => (string) $item->id,
                        'product_id' => $item->product_id,
                        'description' => $item->description,
                        'quantity' => (float) $item->quantity,
                        'quantity_delivered' => (float) $item->quantity_delivered,
                        'remaining' => $remaining,
                        'unit_price' => (float) $item->unit_price,
                    ];
                }
            }
            if ($items !== []) {
                $data[] = ['id' => (string) $order->id, 'order_number' => $order->order_number, 'contact_id' => $order->contact_id, 'currency' => $order->currency ?? 'EUR', 'items' => $items];
            }
        }

        return response()->json(['data' => $data]);
    }
}
