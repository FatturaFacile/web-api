<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Rules\PublicHttpUrl;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Inventory\DTOs\CarrierTrackingEvent;
use Modules\Inventory\Http\Requests\BookShipmentRequest;
use Modules\Inventory\Models\Shipment;
use Modules\Inventory\Models\ShippingCarrier;
use Modules\Inventory\Services\Shipping\ShippingManager;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Shipping', description: 'Carriers, shipments, labels and tracking')]
final class ShippingController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/carriers',
        summary: 'List carriers',
        tags: ['Inventory Shipping'],
        responses: [
            new OA\Response(response: 200, description: 'Carriers', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryCarrier'))])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List carriers', tags: ['Inventory'])]
    public function carriers(): JsonResponse
    {
        return response()->json(['data' => ShippingCarrier::query()->orderBy('code')->get()]);
    }

    #[OA\Post(
        path: '/api/v1/inventory/carriers',
        summary: 'Create a carrier',
        description: 'A carrier and the driver that talks to it. The `local` driver needs no API: it makes its own tracking numbers and labels. The `dpd_ch` driver uses the DPD Switzerland API and needs `credentials` with username, password and sender, and the invoicing number as `contract_number`.',
        tags: ['Inventory Shipping'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['code', 'name', 'driver'],
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'OWN'),
                new OA\Property(property: 'name', type: 'string', example: 'Own vans'),
                new OA\Property(property: 'driver', type: 'string', enum: ['local', 'dpd_ch']),
                new OA\Property(property: 'contract_number', type: 'string'),
                new OA\Property(property: 'credentials', type: 'object', description: 'Stored encrypted and never returned'),
                new OA\Property(property: 'tracking_url_template', type: 'string', example: 'https://carrier.example/track/{tracking_number}'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Carrier created', content: new OA\JsonContent(ref: '#/components/schemas/InventoryCarrier')),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Create a carrier', tags: ['Inventory'])]
    public function storeCarrier(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'driver' => ['required', 'string', Rule::in(ShippingManager::driverNames())],
            'contract_number' => ['nullable', 'string', 'max:100'],
            // DPD accepts only the keys it reads; `base_url` (its staging host) must be a public https address for any driver.
            'credentials' => ['nullable', $request->input('driver') === 'dpd_ch' ? 'array:username,password,sender,service_code,base_url' : 'array'],
            'credentials.username' => ['nullable', 'string', 'max:255'],
            'credentials.password' => ['nullable', 'string', 'max:255'],
            'credentials.service_code' => ['nullable', 'string', 'max:20', 'alpha_dash'],
            'credentials.base_url' => ['nullable', 'string', 'max:255', new PublicHttpUrl(['https'], __('The carrier base URL must be a public https address.'))],
            'credentials.sender' => ['nullable', 'array:name,street,zip_code,city,country_code,phone,email'],
            'credentials.sender.*' => ['nullable', 'string', 'max:255'],
            'tracking_url_template' => ['nullable', 'string', 'max:255'],
        ]);

        // The nested rules only check known keys: the credentials are stored as sent (DPD is limited to its keys above).
        if ($request->has('credentials')) {
            $validated['credentials'] = $request->input('credentials');
        }

        return response()->json(ShippingCarrier::create($validated), 201);
    }

    #[OA\Post(
        path: '/api/v1/inventory/shipments',
        summary: 'Book a shipment',
        description: 'Books the shipment with the driver of the carrier and stores its tracking number and label. Packages and weight come from the packing list when one is given.',
        tags: ['Inventory Shipping'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['shipping_carrier_id', 'recipient'],
            properties: [
                new OA\Property(property: 'shipping_carrier_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'stock_packing_list_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'delivery_note_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'packages_count', type: 'integer'),
                new OA\Property(property: 'gross_weight_kg', type: 'number'),
                new OA\Property(property: 'recipient', ref: '#/components/schemas/InventoryShipmentRecipient'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Shipment booked', content: new OA\JsonContent(ref: '#/components/schemas/InventoryShipment')),
            new OA\Response(response: 422, description: 'Validation failed'),
            new OA\Response(response: 502, description: 'The carrier refused the booking'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Book a shipment', tags: ['Inventory'])]
    public function storeShipment(BookShipmentRequest $request, ShippingManager $shipping): JsonResponse
    {
        $carrier = ShippingCarrier::findOrFail($request->string('shipping_carrier_id')->value());
        $shipment = $shipping->book($carrier, $request->validated(), (string) auth()->id());

        return response()->json($this->present($shipment->load(['carrier', 'trackingEvents'])), 201);
    }

    #[OA\Get(
        path: '/api/v1/inventory/shipments/{id}',
        summary: 'Get a shipment',
        tags: ['Inventory Shipping'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Shipment with its tracking history', content: new OA\JsonContent(ref: '#/components/schemas/InventoryShipment')),
            new OA\Response(response: 404, description: 'Shipment not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Get a shipment', tags: ['Inventory'])]
    public function showShipment(string $id): JsonResponse
    {
        return response()->json($this->present(Shipment::with(['carrier', 'trackingEvents'])->findOrFail($id)));
    }

    #[OA\Get(
        path: '/api/v1/inventory/shipments/{id}/label',
        summary: 'Download a shipment label',
        tags: ['Inventory Shipping'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The label in the format of the carrier: HTML for `local`, PDF for `dpd_ch`', content: [new OA\MediaType(mediaType: 'text/html', schema: new OA\Schema(type: 'string', description: 'HTML page of the label (carrier driver local)')), new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary', description: 'PDF of the label (carrier driver dpd_ch)'))]),
            new OA\Response(response: 404, description: 'Shipment not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Download a shipment label', tags: ['Inventory'])]
    public function label(string $id): Response
    {
        $shipment = Shipment::findOrFail($id);

        if ($shipment->label_format === 'pdf') {
            return response((string) base64_decode((string) $shipment->label_content, true), 200, ['Content-Type' => 'application/pdf']);
        }

        return response((string) $shipment->label_content, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    #[OA\Post(
        path: '/api/v1/inventory/shipments/{id}/tracking',
        summary: 'Add a tracking event',
        description: 'Records a status by hand, for carriers that have no API.',
        tags: ['Inventory Shipping'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['status'],
            properties: [
                new OA\Property(property: 'status', type: 'string', enum: ShippingManager::STATUSES),
                new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'description', type: 'string'),
                new OA\Property(property: 'location', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Event recorded', content: new OA\JsonContent(ref: '#/components/schemas/InventoryShipment')),
            new OA\Response(response: 404, description: 'Shipment not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Add a tracking event', tags: ['Inventory'])]
    public function addTrackingEvent(string $id, Request $request, ShippingManager $shipping): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(ShippingManager::STATUSES)],
            'occurred_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:150'],
        ]);

        $shipment = Shipment::findOrFail($id);
        $shipping->record($shipment, new CarrierTrackingEvent($validated['status'], $validated['occurred_at'] ?? now()->toIso8601String(), $validated['description'] ?? null, $validated['location'] ?? null), 'manual');

        return response()->json($this->present($shipment->load(['carrier', 'trackingEvents'])), 201);
    }

    #[OA\Post(
        path: '/api/v1/inventory/shipments/{id}/refresh',
        summary: 'Refresh tracking from the carrier',
        description: 'Asks the driver of the carrier for news. The `local` driver has none to give.',
        tags: ['Inventory Shipping'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Shipment with its tracking history', content: new OA\JsonContent(ref: '#/components/schemas/InventoryShipment')),
            new OA\Response(response: 404, description: 'Shipment not found'),
            new OA\Response(response: 502, description: 'The carrier could not answer'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Refresh tracking from the carrier', tags: ['Inventory'])]
    public function refresh(string $id, ShippingManager $shipping): JsonResponse
    {
        $shipment = Shipment::with('carrier')->findOrFail($id);

        return response()->json($this->present($shipping->refresh($shipment)->load(['carrier', 'trackingEvents'])));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Shipment $shipment): array
    {
        $template = $shipment->carrier?->tracking_url_template;

        return [
            'id' => $shipment->id,
            'shipment_number' => $shipment->shipment_number,
            'shipping_carrier_id' => $shipment->shipping_carrier_id,
            'stock_packing_list_id' => $shipment->stock_packing_list_id,
            'delivery_note_id' => $shipment->delivery_note_id,
            'status' => $shipment->status,
            'tracking_number' => $shipment->tracking_number,
            'tracking_url' => $template !== null ? str_replace('{tracking_number}', rawurlencode((string) $shipment->tracking_number), $template) : null,
            'recipient' => $shipment->recipient,
            'packages_count' => $shipment->packages_count,
            'gross_weight_kg' => $shipment->gross_weight_kg,
            'label_format' => $shipment->label_format,
            'delivered_at' => $shipment->delivered_at?->toIso8601String(),
            'tracking_events' => $shipment->trackingEvents->map(static fn ($event): array => [
                'status' => $event->status,
                'description' => $event->description,
                'location' => $event->location,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'source' => $event->source,
            ])->all(),
        ];
    }
}
