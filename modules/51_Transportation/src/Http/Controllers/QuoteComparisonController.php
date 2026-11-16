<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Transportation\Http\Controllers\Concerns\InteractsWithTransportation;
use Modules\Transportation\Http\Resources\QuoteResource;
use Modules\Transportation\Models\TmsQuote;
use Modules\Transportation\Services\QuoteService;
use OpenApi\Attributes as OA;

/**
 * Freight quotes comparison: price a shipment with every active carrier, then award the chosen offer.
 */
final class QuoteComparisonController extends Controller
{
    use InteractsWithTransportation;

    public function __construct(private readonly QuoteService $quotes) {}

    #[OA\Post(path: '/api/v1/transportation/quotes', operationId: 'compareTransportationQuotes', description: 'Prices the shipment with every active carrier that has a tariff band for it (zones, weight, distance, valid on the ship date, same currency) and saves the offers ranked cheapest first. When a transport order is given, the weight, distance, zones and date default to its own. 422 when no carrier can price the shipment.', summary: 'Compare carriers for a shipment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'reference', type: 'string', nullable: true), new OA\Property(property: 'transport_order_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'weight_kg', type: 'string', example: '250', nullable: true), new OA\Property(property: 'distance_km', type: 'string', example: '420', nullable: true), new OA\Property(property: 'origin_zone', type: 'string', nullable: true), new OA\Property(property: 'destination_zone', type: 'string', nullable: true), new OA\Property(property: 'ship_date', type: 'string', format: 'date', nullable: true), new OA\Property(property: 'currency', type: 'string', example: 'EUR', nullable: true), new OA\Property(property: 'notes', type: 'string', nullable: true)])), responses: [
        new OA\Response(response: 201, description: 'The saved comparison', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationQuote')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', TmsQuote::class);
        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:100'],
            'transport_order_id' => ['nullable', 'uuid', Rule::exists('tms_transport_orders', 'id')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')],
            'weight_kg' => ['required_without:transport_order_id', 'nullable', 'numeric', 'gt:0', 'max:9999999'],
            'distance_km' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'origin_zone' => ['nullable', 'string', 'max:30'],
            'destination_zone' => ['nullable', 'string', 'max:30'],
            'ship_date' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return (new QuoteResource($this->quotes->compare($this->tenantId(), $data)))->response()->setStatusCode(201);
    }

    #[OA\Post(path: '/api/v1/transportation/quotes/{id}/award', operationId: 'awardTransportationQuote', description: 'Chooses one of the offers of an open quote. When the quote belongs to a transport order, the carrier and the offered amount become the carrier and the expected cost of the order.', summary: 'Award a quote to a carrier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['carrier_id'], properties: [new OA\Property(property: 'carrier_id', type: 'string', format: 'uuid')])), responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationQuote')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function award(Request $request, string $id): QuoteResource
    {
        $record = TmsQuote::query()->findOrFail($id);
        $this->authorize('update', $record);
        $data = $request->validate([
            'carrier_id' => ['required', 'uuid'],
        ]);

        return new QuoteResource($this->quotes->award($record, (string) $data['carrier_id']));
    }
}
