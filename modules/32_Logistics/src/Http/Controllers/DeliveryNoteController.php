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
use Modules\Logistics\Enums\TransportReason;
use Modules\Logistics\Services\DeferredInvoicingService;
use Modules\Logistics\Services\DeliveryNoteService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Logistics DDT', description: 'Delivery notes (DDT) for IT/CH: numbering, issue, delivery, cancel, PDF, deferred invoicing')]
final class DeliveryNoteController extends Controller
{
    public function __construct(private readonly DeliveryNoteService $service) {}

    #[OA\Get(
        path: '/api/v1/logistics/delivery-notes',
        summary: 'List delivery notes',
        description: 'Requires permission logistics.ddt.view.',
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
        description: 'Requires permission logistics.ddt.view.',
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
        description: 'Requires permission logistics.ddt.create.',
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
        description: 'Requires permission logistics.ddt.create.',
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

    #[OA\Post(
        path: '/api/v1/logistics/delivery-notes/{id}/issue',
        summary: 'Issue a draft delivery note (in transit)',
        description: 'Requires permission logistics.ddt.emit.',
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
        description: 'Requires permission logistics.ddt.emit.',
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
        path: '/api/v1/logistics/delivery-notes/{id}/cancel',
        summary: 'Cancel a draft or issued delivery note',
        description: 'Requires permission logistics.ddt.cancel.',
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
        description: 'Requires permission logistics.ddt.emit.',
        tags: ['Logistics DDT'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DeferredInvoiceInput')),
        responses: [
            new OA\Response(response: 201, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function deferredInvoice(Request $request, DeferredInvoicingService $invoicing): JsonResponse
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
            $invoice = $invoicing->invoice($data['delivery_note_ids'], $overrides, (bool) ($data['enforce_same_month'] ?? true));
        } catch (InvalidArgumentException|DomainException $e) {
            return $this->unprocessable($e);
        }

        return response()->json(['data' => $invoice], 201);
    }

    #[OA\Get(
        path: '/api/v1/logistics/delivery-notes/{id}/pdf',
        summary: 'Download the delivery note PDF (IT DPR 472/96 and CH layout)',
        description: 'Requires permission logistics.ddt.view.',
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
    public function pdf(string $id, DocumentPdfService $pdf): Response
    {
        // The legacy model maps the same table and is what the PDF engine renders (single source of truth: delivery_notes).
        $note = LegacyDeliveryNote::query()->findOrFail(SalesDeliveryNote::query()->findOrFail($id)->id);
        $rendered = $pdf->render($note);

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
            'carrier_tracking_url' => ['nullable', 'url', 'max:500'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['nullable', 'string'],
            'show_prices' => ['sometimes', 'boolean'],
        ];
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
