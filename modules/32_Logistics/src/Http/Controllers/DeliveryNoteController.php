<?php

declare(strict_types=1);

namespace Modules\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DeliveryNote as LegacyDeliveryNote;
use App\Services\Pdf\DocumentPdfService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Logistics\Enums\DeliveryNoteType;
use Modules\Logistics\Enums\ShipmentFlow;
use Modules\Logistics\Enums\TransportReason;
use Modules\Logistics\Services\DeliveryNoteService;
use Modules\Logistics\Services\OrderBillingService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Logistics DDT', description: 'Delivery notes (DDT) for IT/CH: numbering, issue, delivery, cancel, PDF, deferred invoicing')]
final class DeliveryNoteController extends Controller
{
    public function __construct(private readonly DeliveryNoteService $service, private readonly OrderBillingService $billing) {}

    #[OA\Get(
        path: '/api/v1/logistics/delivery-notes',
        summary: 'List delivery notes',
        description: 'Requires permission delivery_notes.view.',
        tags: ['Logistics DDT'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'transport_reason_code', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'contact_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'destination_warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $query = SalesDeliveryNote::query()->with('items')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('transport_reason_code'), fn ($q, $v) => $q->where('transport_reason_code', $v))
            ->when($request->query('contact_id'), fn ($q, $v) => $q->where('contact_id', $v))
            ->when($request->query('destination_warehouse_id'), fn ($q, $v) => $q->where('destination_warehouse_id', $v))
            ->when($request->query('date_from'), fn ($q, $v) => $q->whereDate('date', '>=', $v))
            ->when($request->query('date_to'), fn ($q, $v) => $q->whereDate('date', '<=', $v))
            ->orderByDesc('date')->orderByDesc('created_at');

        return response()->json($query->paginate(min(100, (int) $request->query('per_page', 25))));
    }

    #[OA\Get(
        path: '/api/v1/logistics/delivery-notes/{id}',
        summary: 'Get a delivery note with lines and invoice links',
        description: 'Requires permission delivery_notes.view.',
        tags: ['Logistics DDT'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => SalesDeliveryNote::query()->with(['items', 'invoiceLinks'])->findOrFail($id)]);
    }

    #[OA\Post(
        path: '/api/v1/logistics/delivery-notes',
        summary: 'Create a delivery note (free creation)',
        description: 'Requires permission delivery_notes.create.',
        tags: ['Logistics DDT'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DeliveryNoteInput')),
        responses: [
            new OA\Response(response: 201, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->headerRules() + [
            'type' => ['required', Rule::in(DeliveryNoteType::values())],
            ...$this->itemRules(),
        ]);

        try {
            $note = $this->service->create($validated, $validated['items']);
        } catch (InvalidArgumentException|DomainException $e) {
            return $this->unprocessable($e);
        }

        return response()->json(['data' => $note], 201);
    }

    #[OA\Post(
        path: '/api/v1/logistics/delivery-notes/from-orders',
        summary: 'Create one delivery note from one or more sales orders (total or partial fulfilment)',
        description: 'Requires permission delivery_notes.create.',
        tags: ['Logistics DDT'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DeliveryNoteFromOrdersInput')),
        responses: [
            new OA\Response(response: 201, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function fromOrders(Request $request): JsonResponse
    {
        $validated = $request->validate($this->headerRules() + [
            'orders' => ['required', 'array', 'min:1'],
            'orders.*.sales_order_id' => ['required', 'uuid'],
            'orders.*.items' => ['sometimes', 'array', 'min:1'],
            'orders.*.items.*.sales_order_item_id' => ['required', 'uuid'],
            'orders.*.items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $orders = $validated['orders'];
        unset($validated['orders']);

        try {
            $note = $this->service->createFromOrders($orders, $validated);
        } catch (InvalidArgumentException|DomainException $e) {
            return $this->unprocessable($e);
        }

        return response()->json(['data' => $note], 201);
    }

    #[OA\Put(
        path: '/api/v1/logistics/delivery-notes/{id}',
        summary: 'Edit a draft delivery note (header and lines)',
        description: 'Requires permission delivery_notes.update.',
        tags: ['Logistics DDT'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate($this->headerRules() + ['items' => ['sometimes', 'array', 'min:1']] + $this->itemRulesOptional());
        $items = $validated['items'] ?? null;
        unset($validated['items']);

        return $this->transition(fn (SalesDeliveryNote $n) => $this->service->update($n, $validated, $items), $id);
    }

    #[OA\Delete(
        path: '/api/v1/logistics/delivery-notes/{id}',
        summary: 'Delete a standalone draft delivery note',
        description: 'Requires permission delivery_notes.delete.',
        tags: ['Logistics DDT'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $note = SalesDeliveryNote::query()->findOrFail($id);

        try {
            $this->service->delete($note);
        } catch (DomainException $e) {
            return $this->unprocessable($e);
        }

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/logistics/delivery-notes/{id}/dispatch',
        summary: 'Dispatch an issued delivery note (in transit, books the carrier shipment)',
        description: 'Requires permission delivery_notes.issue.',
        tags: ['Logistics DDT'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function dispatch(string $id): JsonResponse
    {
        return $this->transition(fn (SalesDeliveryNote $n) => $this->service->startTransit($n), $id);
    }

    #[OA\Get(
        path: '/api/v1/logistics/delivery-notes/unbilled',
        summary: 'Unbilled delivery notes grouped by customer',
        description: 'Requires permission delivery_notes.invoice.',
        tags: ['Logistics DDT'],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function unbilled(): JsonResponse
    {
        return response()->json(['data' => $this->billing->unbilledByCustomer()]);
    }

    #[OA\Post(
        path: '/api/v1/logistics/delivery-notes/deferred-invoicing/run',
        summary: 'Issue cumulative deferred invoices for all unbilled delivery notes (per customer and month)',
        description: 'Requires permission delivery_notes.invoice.',
        tags: ['Logistics DDT'],
        responses: [
            new OA\Response(response: 201, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function runDeferredInvoicing(Request $request): JsonResponse
    {
        $data = $request->validate(['up_to' => ['sometimes', 'date'], 'contact_id' => ['sometimes', 'uuid']]);

        try {
            $invoices = $this->billing->runDeferredInvoicing(isset($data['up_to']) ? now()->parse($data['up_to']) : null, $data['contact_id'] ?? null);
        } catch (InvalidArgumentException|DomainException $e) {
            return $this->unprocessable($e);
        }

        return response()->json(['data' => $invoices], 201);
    }

    #[OA\Post(
        path: '/api/v1/logistics/delivery-notes/{id}/issue',
        summary: 'Issue a draft delivery note (in transit)',
        description: 'Requires permission delivery_notes.issue.',
        tags: ['Logistics DDT'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function issue(string $id): JsonResponse
    {
        return $this->transition(fn (SalesDeliveryNote $n) => $this->service->issue($n), $id);
    }

    #[OA\Post(
        path: '/api/v1/logistics/delivery-notes/{id}/deliver',
        summary: 'Mark an issued delivery note as delivered',
        description: 'Requires permission delivery_notes.issue.',
        tags: ['Logistics DDT'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function deliver(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['recipient_signature_name' => ['nullable', 'string', 'max:150'], 'delivered_at' => ['nullable', 'date']]);

        return $this->transition(fn (SalesDeliveryNote $n) => $this->service->markDelivered(
            $n,
            $data['recipient_signature_name'] ?? null,
            isset($data['delivered_at']) ? now()->parse($data['delivered_at']) : null,
        ), $id);
    }

    #[OA\Post(
        path: '/api/v1/logistics/delivery-notes/{id}/awb',
        summary: 'Link the carrier waybill (AWB / lettera di vettura) to a delivery note',
        description: 'Requires permission delivery_notes.issue. Allowed on issued and dispatched documents, so the waybill can be added once the carrier has taken the goods.',
        tags: ['Logistics DDT'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['awb_number'], properties: [
            new OA\Property(property: 'awb_number', type: 'string', maxLength: 60),
            new OA\Property(property: 'carrier_tracking_url', type: 'string', format: 'uri', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function assignAwb(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['awb_number' => ['required', 'string', 'max:60'], 'carrier_tracking_url' => ['nullable', 'url', 'max:500']]);

        return $this->transition(fn (SalesDeliveryNote $n) => $this->service->assignAwb($n, $data['awb_number'], $data['carrier_tracking_url'] ?? null), $id);
    }

    #[OA\Post(
        path: '/api/v1/logistics/delivery-notes/{id}/accept-approval',
        summary: 'Customer accepted goods sent on approval (conto visione): the document becomes a sale and can be invoiced',
        description: 'Requires permission delivery_notes.issue.',
        tags: ['Logistics DDT'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function acceptApproval(string $id): JsonResponse
    {
        return $this->transition(fn (SalesDeliveryNote $n) => $this->service->acceptOnApproval($n), $id);
    }

    #[OA\Post(
        path: '/api/v1/logistics/delivery-notes/{id}/cancel',
        summary: 'Cancel a draft or issued delivery note',
        description: 'Requires permission delivery_notes.cancel.',
        tags: ['Logistics DDT'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        return $this->transition(fn (SalesDeliveryNote $n) => $this->service->cancel($n), $id);
    }

    #[OA\Post(
        path: '/api/v1/logistics/delivery-notes/deferred-invoice',
        summary: 'Generate a cumulative deferred invoice from several delivery notes',
        description: 'Requires permission delivery_notes.issue.',
        tags: ['Logistics DDT'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DeferredInvoiceInput')),
        responses: [
            new OA\Response(response: 201, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function deferredInvoice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'delivery_note_ids' => ['required', 'array', 'min:1'],
            'delivery_note_ids.*' => ['required', 'uuid'],
            'enforce_same_month' => ['sometimes', 'boolean'],
            'due_date' => ['sometimes', 'date'],
            'payment_method' => ['sometimes', 'string', 'max:10'],
            'payment_terms' => ['sometimes', 'string', 'max:10'],
        ]);

        $overrides = array_intersect_key($data, array_flip(['due_date', 'payment_method', 'payment_terms']));

        try {
            $invoice = $this->billing->issueDeferredInvoice($data['delivery_note_ids'], $overrides, (bool) ($data['enforce_same_month'] ?? true));
        } catch (InvalidArgumentException|DomainException $e) {
            return $this->unprocessable($e);
        }

        return response()->json(['data' => $invoice], 201);
    }

    #[OA\Get(
        path: '/api/v1/logistics/delivery-notes/{id}/pdf',
        summary: 'Download the delivery note PDF (IT DPR 472/96 and CH layout)',
        description: 'Requires permission delivery_notes.view.',
        tags: ['Logistics DDT'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function pdf(Request $request, string $id, DocumentPdfService $pdf): Response
    {
        $options = $request->validate([
            'hide_prices' => ['sometimes', 'boolean'],
            'lang' => ['sometimes', Rule::in(['it', 'en', 'de', 'fr'])],
        ]);

        // The legacy model maps the same table and extends the canonical one: single source of truth is delivery_notes.
        $note = LegacyDeliveryNote::query()->findOrFail(SalesDeliveryNote::query()->findOrFail($id)->id);
        $rendered = $pdf->render($note, [
            'hide_prices' => $request->boolean('hide_prices'),
            'locale' => $options['lang'] ?? null,
        ]);

        return response($rendered->content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$rendered->filename.'"',
        ]);
    }

    /**
     * @param  callable(SalesDeliveryNote): SalesDeliveryNote  $action
     */
    private function transition(callable $action, string $id): JsonResponse
    {
        $note = SalesDeliveryNote::query()->findOrFail($id);

        try {
            return response()->json(['data' => $action($note)]);
        } catch (DomainException $e) {
            return $this->unprocessable($e);
        }
    }

    private function unprocessable(\Throwable $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage()], 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function headerRules(): array
    {
        return [
            'contact_id' => ['nullable', 'uuid'],
            'supplier_id' => ['nullable', 'uuid'],
            'carrier_id' => ['nullable', 'uuid'],
            'origin_warehouse_id' => ['nullable', 'uuid'],
            'destination_warehouse_id' => ['nullable', 'uuid'],
            'shipping_address_id' => ['nullable', 'uuid'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'date' => ['sometimes', 'date'],
            'transport_reason_code' => ['sometimes', Rule::in(TransportReason::values())],
            'carriage_terms' => ['nullable', Rule::in(['free', 'collect', 'free_with_charge'])],
            'transport_by' => ['nullable', Rule::in(['sender', 'recipient', 'carrier'])],
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'carrier_vat_number' => ['nullable', 'string', 'max:40'],
            'carrier_license_plate' => ['nullable', 'string', 'max:30'],
            'departure_time' => ['nullable', 'date'],
            'goods_appearance' => ['nullable', 'string', 'max:100'],
            'packages_count' => ['nullable', 'integer', 'min:0'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'net_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'volume_m3' => ['nullable', 'numeric', 'min:0'],
            'incoterms' => ['nullable', Rule::in(['EXW', 'FCA', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP', 'FAS', 'FOB', 'CFR', 'CIF'])],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'awb_number' => ['nullable', 'string', 'max:60'],
            'shipment_flow' => ['sometimes', Rule::in(ShipmentFlow::values())],
            'sender_display' => ['sometimes', Rule::in(['company', 'blank', 'custom'])],
            'sender_name' => ['nullable', 'string', 'max:255'],
            'sender_address' => ['nullable', 'string', 'max:1000'],
            'final_recipient_name' => ['nullable', 'string', 'max:255'],
            'carrier_tracking_url' => ['nullable', 'url', 'max:500'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['nullable', 'string'],
            'show_prices' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRulesOptional(): array
    {
        $rules = $this->itemRules();
        unset($rules['items']);

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'uuid'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.uom' => ['nullable', 'string', 'max:20'],
            'items.*.batch_number' => ['nullable', 'string', 'max:100'],
            'items.*.serial_number' => ['nullable', 'string', 'max:100'],
        ];
    }
}
