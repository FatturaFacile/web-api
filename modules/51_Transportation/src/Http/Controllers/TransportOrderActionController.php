<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Transportation\Http\Controllers\Concerns\InteractsWithTransportation;
use Modules\Transportation\Http\Resources\TransportOrderResource;
use Modules\Transportation\Models\TmsTransportOrder;
use Modules\Transportation\Services\TransportOrderService;
use OpenApi\Attributes as OA;

/**
 * The life of a transport order: plan it, assign a carrier, cancel it. Tracking events move it on the road.
 */
final class TransportOrderActionController extends Controller
{
    use InteractsWithTransportation;

    public function __construct(private readonly TransportOrderService $orders) {}

    #[OA\Post(path: '/api/v1/transportation/transport-orders/{id}/plan', operationId: 'planTransportationOrder', description: 'A draft order becomes planned: it can now be consolidated on a trip and receive tracking events.', summary: 'Plan a transport order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTransportOrder')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function plan(string $id): TransportOrderResource
    {
        $record = TmsTransportOrder::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new TransportOrderResource($this->orders->plan($record));
    }

    #[OA\Post(path: '/api/v1/transportation/transport-orders/{id}/assign-carrier', operationId: 'assignCarrierTransportationOrder', description: 'Assigns an active carrier to a draft or planned order. The expected cost is the one given, else the rate of the carrier for the order (422 when no band matches).', summary: 'Assign a carrier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['carrier_id'], properties: [new OA\Property(property: 'carrier_id', type: 'string', format: 'uuid'), new OA\Property(property: 'expected_cost', type: 'string', example: '180.00', nullable: true)])), responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTransportOrder')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function assignCarrier(Request $request, string $id): TransportOrderResource
    {
        $record = TmsTransportOrder::query()->findOrFail($id);
        $this->authorize('update', $record);
        $data = $request->validate([
            'carrier_id' => ['required', 'uuid', Rule::exists('tms_carriers', 'id')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')],
            'expected_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ]);

        return new TransportOrderResource($this->orders->assignCarrier($record, (string) $data['carrier_id'], isset($data['expected_cost']) ? (string) $data['expected_cost'] : null));
    }

    #[OA\Post(path: '/api/v1/transportation/transport-orders/{id}/cancel', operationId: 'cancelTransportationOrder', description: 'Cancels a draft or planned order that is not on a trip.', summary: 'Cancel a transport order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTransportOrder')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function cancel(string $id): TransportOrderResource
    {
        $record = TmsTransportOrder::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new TransportOrderResource($this->orders->cancel($record));
    }
}
