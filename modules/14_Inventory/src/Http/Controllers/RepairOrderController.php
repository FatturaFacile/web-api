<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Modules\Inventory\Models\RepairOrder;
use Modules\Inventory\Models\StockSerialNumber;
use Modules\Inventory\Services\RepairOrderService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Warranties and Repairs', description: 'Warranties by serial number and repair orders')]
final class RepairOrderController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    #[OA\Get(
        path: '/api/v1/inventory/warranties/lookup',
        summary: 'Look up a warranty by serial number',
        tags: ['Inventory Warranties and Repairs'],
        parameters: [new OA\Parameter(name: 'serial_number', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Warranty of the serial', content: new OA\JsonContent(ref: '#/components/schemas/SerialWarranty')),
            new OA\Response(response: 404, description: 'Serial not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Look up a warranty by serial number', tags: ['Inventory'])]
    public function lookup(Request $request, RepairOrderService $repairs): JsonResponse
    {
        $validated = $request->validate(['serial_number' => ['required', 'string', 'max:100']]);
        $serial = StockSerialNumber::query()->with('item')->where('serial_number', $validated['serial_number'])->firstOrFail();

        return response()->json($this->warrantyOf($serial, $repairs));
    }

    #[OA\Put(
        path: '/api/v1/inventory/serials/{id}/warranty',
        summary: 'Record the sale and warranty of a serial',
        description: 'The customer, the purchase value and the warranty dates of one serial. The period is the seller\'s: nothing is assumed.',
        tags: ['Inventory Warranties and Repairs'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['contact_id', 'warranty_start_date', 'warranty_end_date'],
            properties: [
                new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'purchase_date', type: 'string', format: 'date'),
                new OA\Property(property: 'purchase_value', type: 'string', example: '1299.00'),
                new OA\Property(property: 'purchase_currency', type: 'string', example: 'CHF'),
                new OA\Property(property: 'warranty_start_date', type: 'string', format: 'date'),
                new OA\Property(property: 'warranty_end_date', type: 'string', format: 'date'),
                new OA\Property(property: 'warranty_terms', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Warranty recorded', content: new OA\JsonContent(ref: '#/components/schemas/SerialWarranty')),
            new OA\Response(response: 404, description: 'Serial not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Record the sale and warranty of a serial', tags: ['Inventory'])]
    public function recordWarranty(string $id, Request $request, RepairOrderService $repairs): JsonResponse
    {
        $serial = StockSerialNumber::query()->with('item')->findOrFail($id);
        $serial->update($request->validate([
            'contact_id' => ['required', 'uuid', $this->tenantExists('contacts')],
            'purchase_date' => ['nullable', 'date'],
            'purchase_value' => ['nullable', 'decimal:0,2', 'min:0'],
            'purchase_currency' => ['nullable', 'required_with:purchase_value', 'string', 'size:3'],
            'warranty_start_date' => ['required', 'date'],
            'warranty_end_date' => ['required', 'date', 'after_or_equal:warranty_start_date'],
            'warranty_terms' => ['nullable', 'string', 'max:2000'],
        ]));

        return response()->json($this->warrantyOf($serial, $repairs));
    }

    #[OA\Get(
        path: '/api/v1/inventory/repair-orders',
        summary: 'List repair orders',
        tags: ['Inventory Warranties and Repairs'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'contact_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'stock_serial_number_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated repair orders', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/RepairOrder'))])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List repair orders', tags: ['Inventory'])]
    public function index(Request $request): JsonResponse
    {
        $query = RepairOrder::query()->orderByDesc('received_at');

        foreach (['status', 'contact_id', 'stock_serial_number_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        return response()->json($query->paginate(25));
    }

    #[OA\Post(
        path: '/api/v1/inventory/repair-orders',
        summary: 'Open a repair order',
        description: 'Links serial, customer and purchase value, and records whether the warranty covers the intake date.',
        tags: ['Inventory Warranties and Repairs'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['stock_serial_number_id', 'fault_description'],
            properties: [
                new OA\Property(property: 'stock_serial_number_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', description: 'By default the customer of the serial'),
                new OA\Property(property: 'fault_description', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Repair opened', content: new OA\JsonContent(ref: '#/components/schemas/RepairOrder')),
            new OA\Response(response: 422, description: 'Validation failed, or the serial has no customer'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Open a repair order', tags: ['Inventory'])]
    public function store(Request $request, RepairOrderService $repairs): JsonResponse
    {
        $validated = $request->validate([
            'stock_serial_number_id' => ['required', 'uuid', $this->tenantExists('stock_serial_numbers')],
            'contact_id' => ['nullable', 'uuid', $this->tenantExists('contacts')],
            'fault_description' => ['required', 'string', 'max:5000'],
        ]);

        return response()->json($repairs->open(StockSerialNumber::findOrFail($validated['stock_serial_number_id']), $validated, (string) auth()->id()), 201);
    }

    #[OA\Get(
        path: '/api/v1/inventory/repair-orders/{id}',
        summary: 'Get a repair order',
        tags: ['Inventory Warranties and Repairs'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Repair order with its parts', content: new OA\JsonContent(ref: '#/components/schemas/RepairOrder')),
            new OA\Response(response: 404, description: 'Repair order not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Get a repair order', tags: ['Inventory'])]
    public function show(string $id): JsonResponse
    {
        return response()->json(RepairOrder::with(['lines', 'serial'])->findOrFail($id));
    }

    #[OA\Post(
        path: '/api/v1/inventory/repair-orders/{id}/status',
        summary: 'Move a repair to its next status',
        tags: ['Inventory Warranties and Repairs'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['status'],
            properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['diagnosing', 'waiting_parts', 'repairing', 'ready', 'delivered', 'cancelled']),
                new OA\Property(property: 'diagnosis', type: 'string'),
                new OA\Property(property: 'resolution', type: 'string'),
                new OA\Property(property: 'labour_amount', type: 'string', example: '85.00'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Repair updated', content: new OA\JsonContent(ref: '#/components/schemas/RepairOrder')),
            new OA\Response(response: 404, description: 'Repair order not found'),
            new OA\Response(response: 422, description: 'That step is not allowed from the current status'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Move a repair to its next status', tags: ['Inventory'])]
    public function advance(string $id, Request $request, RepairOrderService $repairs): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:diagnosing,waiting_parts,repairing,ready,delivered,cancelled'],
            'diagnosis' => ['nullable', 'string', 'max:5000'],
            'resolution' => ['nullable', 'string', 'max:5000'],
            'labour_amount' => ['nullable', 'decimal:0,2', 'min:0'],
        ]);

        return response()->json($repairs->advance(RepairOrder::findOrFail($id), $validated));
    }

    #[OA\Post(
        path: '/api/v1/inventory/repair-orders/{id}/parts',
        summary: 'Use a spare part',
        description: 'Takes the part out of the given bin and adds its stock value to the repair.',
        tags: ['Inventory Warranties and Repairs'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['stock_item_id', 'quantity', 'source_bin_id'],
            properties: [
                new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'quantity', type: 'number'),
                new OA\Property(property: 'source_bin_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'lot_id', type: 'string', format: 'uuid'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Part used', content: new OA\JsonContent(ref: '#/components/schemas/RepairOrderLine')),
            new OA\Response(response: 404, description: 'Repair order not found'),
            new OA\Response(response: 422, description: 'Validation failed, not enough stock, or the repair is closed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Use a spare part', tags: ['Inventory'])]
    public function addPart(string $id, Request $request, RepairOrderService $repairs): JsonResponse
    {
        $validated = $request->validate([
            'stock_item_id' => ['required', 'uuid', $this->tenantExists('stock_items')],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'source_bin_id' => ['required', 'uuid', $this->tenantExists('warehouse_bins')],
            'lot_id' => ['nullable', 'uuid', $this->tenantExists('stock_lots')],
        ]);

        return response()->json($repairs->addPart(RepairOrder::findOrFail($id), $validated, (string) auth()->id()), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function warrantyOf(StockSerialNumber $serial, RepairOrderService $repairs): array
    {
        return [
            'stock_serial_number_id' => $serial->id,
            'serial_number' => $serial->serial_number,
            'stock_item_id' => $serial->stock_item_id,
            'item_name' => $serial->item?->name,
            'contact_id' => $serial->contact_id,
            'purchase_date' => $serial->purchase_date?->toDateString(),
            'purchase_value' => $serial->purchase_value,
            'purchase_currency' => $serial->purchase_currency,
            'warranty_start_date' => $serial->warranty_start_date?->toDateString(),
            'warranty_end_date' => $serial->warranty_end_date?->toDateString(),
            'warranty_terms' => $serial->warranty_terms,
            'warranty' => $repairs->warrantyStatus($serial),
            'open_repairs' => RepairOrder::query()->where('stock_serial_number_id', $serial->id)->whereNotIn('status', ['delivered', 'cancelled'])->count(),
        ];
    }

    private function tenantExists(string $table): Exists
    {
        $tenantId = $this->tenantContext->getTenantId();

        return Rule::exists($table, 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
    }
}
