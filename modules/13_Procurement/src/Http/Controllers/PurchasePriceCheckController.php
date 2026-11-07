<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PassiveInvoice;
use App\Services\Tenancy\TenantContext;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Services\PurchasePriceCheckService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Procurement Price Checks', description: 'Cross-check of invoiced purchase prices against orders and supplier price lists')]
final class PurchasePriceCheckController extends Controller
{
    #[OA\Post(
        path: '/api/v1/procurement/price-checks',
        summary: 'Check invoiced purchase prices',
        description: 'Compares each invoiced price with the order and the valid supplier price list. Lines come from the request, or from the FatturaPA XML of `passive_invoice_id`. Nothing is stored.',
        tags: ['Procurement Price Checks'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['purchase_order_id'],
            properties: [
                new OA\Property(property: 'purchase_order_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'passive_invoice_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'date', type: 'string', format: 'date', description: 'The date of the list prices; by default that of the invoice'),
                new OA\Property(property: 'tolerance_percent', type: 'number', default: 0),
                new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'code', type: 'string'),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'quantity', type: 'number'),
                    new OA\Property(property: 'unit_price', type: 'number'),
                ])),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Result per line', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPriceCheck')),
            new OA\Response(response: 404, description: 'Order or invoice not found'),
            new OA\Response(response: 422, description: 'Validation failed, or no lines to check'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Check invoiced purchase prices', tags: ['Procurement'])]
    public function __invoke(Request $request, PurchasePriceCheckService $checks, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $validated = $request->validate([
            'purchase_order_id' => ['required', 'uuid', Rule::exists('purchase_orders', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'passive_invoice_id' => ['nullable', 'required_without:lines', 'uuid', Rule::exists('passive_invoices', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'date' => ['nullable', 'date'],
            'tolerance_percent' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['nullable', 'array', 'min:1'],
            'lines.*.code' => ['nullable', 'string', 'max:100'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.quantity' => ['nullable', 'numeric'],
            'lines.*.unit_price' => ['required', 'numeric'],
        ]);

        // Validation rebuilds the array rule by rule, so the lines come back in the order of the first rule they match.
        $lines = $validated['lines'] ?? null;

        if ($lines !== null) {
            ksort($lines);
        }

        return response()->json($checks->check(
            PurchaseOrder::findOrFail($validated['purchase_order_id']),
            $lines === null ? null : array_values($lines),
            isset($validated['passive_invoice_id']) ? PassiveInvoice::findOrFail($validated['passive_invoice_id']) : null,
            bcadd((string) ($validated['tolerance_percent'] ?? '0'), '0', 2),
            $validated['date'] ?? null,
        ));
    }
}
