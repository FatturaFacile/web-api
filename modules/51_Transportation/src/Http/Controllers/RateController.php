<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Transportation\Http\Controllers\Concerns\InteractsWithTransportation;
use Modules\Transportation\Models\TmsCarrier;
use Modules\Transportation\Models\TmsTariffTable;
use Modules\Transportation\Services\RateCalculator;
use OpenApi\Attributes as OA;

/**
 * Rate calculation of one carrier for a shipment.
 */
final class RateController extends Controller
{
    use InteractsWithTransportation;

    public function __construct(private readonly RateCalculator $rates) {}

    #[OA\Post(path: '/api/v1/transportation/rates/calculate', operationId: 'calculateTransportationRate', description: 'Prices a shipment with the active tariff tables of one carrier valid on the date: the most specific band matching the zones, the weight and the distance gives the fixed amount plus the price per kg and per km the rate basis allows, raised to the minimum charge, plus the fuel surcharge. 422 when no band matches.', summary: 'Calculate a freight rate', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['carrier_id', 'weight_kg'], properties: [new OA\Property(property: 'carrier_id', type: 'string', format: 'uuid'), new OA\Property(property: 'weight_kg', type: 'string', example: '250'), new OA\Property(property: 'distance_km', type: 'string', example: '420', nullable: true), new OA\Property(property: 'origin_zone', type: 'string', nullable: true), new OA\Property(property: 'destination_zone', type: 'string', nullable: true), new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true), new OA\Property(property: 'currency', type: 'string', example: 'EUR', nullable: true)])), responses: [
        new OA\Response(response: 200, description: 'The rate with its breakdown', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationRateOffer')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function calculate(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TmsTariffTable::class);
        $data = $request->validate([
            'carrier_id' => ['required', 'uuid', Rule::exists('tms_carriers', 'id')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')],
            'weight_kg' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'distance_km' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'origin_zone' => ['nullable', 'string', 'max:30'],
            'destination_zone' => ['nullable', 'string', 'max:30'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $carrier = TmsCarrier::query()->findOrFail($data['carrier_id']);
        $offer = $this->rates->rate($carrier, (string) $data['weight_kg'], isset($data['distance_km']) ? (string) $data['distance_km'] : null, $data['origin_zone'] ?? null, $data['destination_zone'] ?? null, $data['date'] ?? now()->toDateString(), $data['currency'] ?? null);

        if ($offer === null) {
            throw ValidationException::withMessages(['carrier_id' => [__('transportation_errors.rate.no_rate', ['carrier' => $carrier->name])]]);
        }

        return response()->json(['data' => $offer]);
    }
}
