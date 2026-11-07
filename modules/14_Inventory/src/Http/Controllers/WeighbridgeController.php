<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Inventory\Http\Requests\BillWeighbridgeTicketRequest;
use Modules\Inventory\Http\Requests\WeighbridgeTicketRequest;
use Modules\Inventory\Models\WeighbridgeReading;
use Modules\Inventory\Models\WeighbridgeTicket;
use Modules\Inventory\Models\WeighbridgeVehicle;
use Modules\Inventory\Services\WeighbridgeService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Weighbridge', description: 'Vehicle cards, gross and tare weighings, device readings and billing by weight or volume')]
final class WeighbridgeController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/weighbridge/vehicles',
        summary: 'List vehicle cards',
        tags: ['Inventory Weighbridge'],
        responses: [
            new OA\Response(response: 200, description: 'Vehicle cards', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WeighbridgeVehicle'))])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List vehicle cards', tags: ['Inventory'])]
    public function vehicles(): JsonResponse
    {
        return response()->json(['data' => WeighbridgeVehicle::query()->orderBy('license_plate')->get()]);
    }

    #[OA\Post(
        path: '/api/v1/inventory/weighbridge/vehicles',
        summary: 'Create a vehicle card',
        tags: ['Inventory Weighbridge'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['license_plate'],
            properties: [
                new OA\Property(property: 'license_plate', type: 'string', example: 'TI 123456'),
                new OA\Property(property: 'card_number', type: 'string'),
                new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'stored_tare_kg', type: 'number', example: 7450),
                new OA\Property(property: 'tare_recorded_on', type: 'string', format: 'date'),
                new OA\Property(property: 'notes', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Vehicle card created', content: new OA\JsonContent(ref: '#/components/schemas/WeighbridgeVehicle')),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Create a vehicle card', tags: ['Inventory'])]
    public function storeVehicle(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $validated = $request->validate([
            'license_plate' => ['required', 'string', 'max:30', Rule::unique('weighbridge_vehicles', 'license_plate')->where('tenant_id', $tenantId)],
            'card_number' => ['nullable', 'string', 'max:50'],
            'contact_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'stored_tare_kg' => ['nullable', 'numeric', 'gt:0'],
            'tare_recorded_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(WeighbridgeVehicle::create($validated), 201);
    }

    #[OA\Post(
        path: '/api/v1/inventory/weighbridge/readings',
        summary: 'Store a device reading',
        description: 'A value read from a weighbridge, sent by whatever integrates the device. Tickets can take their weight from it.',
        tags: ['Inventory Weighbridge'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['device_code', 'weight_kg'],
            properties: [
                new OA\Property(property: 'device_code', type: 'string', example: 'PESA-1'),
                new OA\Property(property: 'weight_kg', type: 'number', example: 18240),
                new OA\Property(property: 'is_stable', type: 'boolean'),
                new OA\Property(property: 'read_at', type: 'string', format: 'date-time'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Reading stored', content: new OA\JsonContent(ref: '#/components/schemas/WeighbridgeReading')),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Store a device reading', tags: ['Inventory'])]
    public function storeReading(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_code' => ['required', 'string', 'max:50'],
            'weight_kg' => ['required', 'numeric', 'min:0'],
            'is_stable' => ['nullable', 'boolean'],
            'read_at' => ['nullable', 'date'],
        ]);

        return response()->json(WeighbridgeReading::create($validated + ['read_at' => now(), 'is_stable' => true]), 201);
    }

    #[OA\Get(
        path: '/api/v1/inventory/weighbridge/readings/latest',
        summary: 'Latest reading of a device',
        tags: ['Inventory Weighbridge'],
        parameters: [new OA\Parameter(name: 'device_code', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Latest reading', content: new OA\JsonContent(ref: '#/components/schemas/WeighbridgeReading')),
            new OA\Response(response: 404, description: 'No reading from this device'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Latest reading of a device', tags: ['Inventory'])]
    public function latestReading(Request $request): JsonResponse
    {
        $validated = $request->validate(['device_code' => ['required', 'string', 'max:50']]);

        return response()->json(WeighbridgeReading::query()->where('device_code', $validated['device_code'])->orderByDesc('read_at')->firstOrFail());
    }

    #[OA\Get(
        path: '/api/v1/inventory/weighbridge/tickets',
        summary: 'List weighing tickets',
        tags: ['Inventory Weighbridge'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'closed', 'billed'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated tickets', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WeighbridgeTicket'))])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List weighing tickets', tags: ['Inventory'])]
    public function tickets(Request $request): JsonResponse
    {
        return response()->json(WeighbridgeTicket::query()
            ->when($request->filled('status'), static fn ($query) => $query->where('status', $request->query('status')))
            ->orderByDesc('first_weighed_at')
            ->paginate((int) $request->query('per_page', 25)));
    }

    #[OA\Post(
        path: '/api/v1/inventory/weighbridge/tickets',
        summary: 'First weighing',
        description: 'Opens a ticket with the first weight, typed or taken from a device reading.',
        tags: ['Inventory Weighbridge'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['weighbridge_vehicle_id', 'direction'],
            properties: [
                new OA\Property(property: 'weighbridge_vehicle_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'direction', type: 'string', enum: ['inbound', 'outbound']),
                new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'weight_kg', type: 'number'),
                new OA\Property(property: 'reading_id', type: 'string', format: 'uuid'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Ticket opened', content: new OA\JsonContent(ref: '#/components/schemas/WeighbridgeTicket')),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'First weighing', tags: ['Inventory'])]
    public function open(WeighbridgeTicketRequest $request, WeighbridgeService $weighbridge): JsonResponse
    {
        $vehicle = WeighbridgeVehicle::findOrFail($request->string('weighbridge_vehicle_id')->value());

        return response()->json($weighbridge->open($vehicle, $request->validated(), (string) auth()->id()), 201);
    }

    #[OA\Post(
        path: '/api/v1/inventory/weighbridge/tickets/{id}/second-weighing',
        summary: 'Second weighing',
        description: 'Closes the ticket with a second weight or the tare stored on the vehicle card. Gross, tare and net follow.',
        tags: ['Inventory Weighbridge'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'weight_kg', type: 'number'),
            new OA\Property(property: 'reading_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'use_stored_tare', type: 'boolean'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Ticket closed', content: new OA\JsonContent(ref: '#/components/schemas/WeighbridgeTicket')),
            new OA\Response(response: 404, description: 'Ticket not found'),
            new OA\Response(response: 422, description: 'Already closed, or no weight available'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Second weighing', tags: ['Inventory'])]
    public function secondWeighing(string $id, WeighbridgeTicketRequest $request, WeighbridgeService $weighbridge): JsonResponse
    {
        return response()->json($weighbridge->close(WeighbridgeTicket::findOrFail($id), $request->validated()));
    }

    #[OA\Post(
        path: '/api/v1/inventory/weighbridge/tickets/{id}/bill',
        summary: 'Bill a ticket on a delivery line',
        description: 'Sets the quantity of a delivery note line from the net weight, in kg, tonnes, or m3 through the density of the load.',
        tags: ['Inventory Weighbridge'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['delivery_note_item_id', 'billing_uom'],
            properties: [
                new OA\Property(property: 'delivery_note_item_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'billing_uom', type: 'string', enum: ['kg', 't', 'm3']),
                new OA\Property(property: 'density_kg_m3', type: 'number', description: 'Required for m3'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Ticket billed', content: new OA\JsonContent(ref: '#/components/schemas/WeighbridgeTicket')),
            new OA\Response(response: 404, description: 'Ticket not found'),
            new OA\Response(response: 422, description: 'The ticket or the delivery note cannot be billed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Bill a ticket on a delivery line', tags: ['Inventory'])]
    public function bill(string $id, BillWeighbridgeTicketRequest $request, WeighbridgeService $weighbridge): JsonResponse
    {
        return response()->json($weighbridge->bill(WeighbridgeTicket::findOrFail($id), $request->validated()));
    }
}
