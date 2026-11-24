<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\TravelExpense\Http\Controllers\Concerns\InteractsWithTravel;
use Modules\TravelExpense\Http\Requests\PerDiemRequest;
use Modules\TravelExpense\Http\Resources\PerDiemDayResource;
use Modules\TravelExpense\Models\TravelPerDiemDay;
use Modules\TravelExpense\Models\TravelTrip;
use Modules\TravelExpense\Services\PerDiemService;
use OpenApi\Attributes as OA;

/**
 * The per-diem days of a business trip.
 */
final class PerDiemController extends Controller
{
    use InteractsWithTravel;

    public function __construct(private readonly PerDiemService $perDiem) {}

    #[OA\Get(path: '/api/v1/travel/trips/{id}/per-diem', operationId: 'listTravelPerDiemDays', description: 'The calculated per-diem days of the trip with their total, tax-free and taxable amounts (the taxable part is what payroll adds to the pay of the employee).', summary: 'Per-diem days of a trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 200, description: 'Per-diem days', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TravelPerDiemDay')),
            new OA\Property(property: 'totals', type: 'object', properties: [new OA\Property(property: 'amount', type: 'string'), new OA\Property(property: 'tax_free_amount', type: 'string'), new OA\Property(property: 'taxable_amount', type: 'string'), new OA\Property(property: 'currency', type: 'string')]),
        ])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
    ])]
    public function index(string $id): JsonResponse
    {
        $trip = $this->findTrip($id);
        $this->authorize('view', $trip);

        return $this->respond($trip, $trip->perDiemDays()->get());
    }

    #[OA\Put(path: '/api/v1/travel/trips/{id}/per-diem', operationId: 'calculateTravelPerDiem', description: 'Calculates the per-diem days of the trip from the rate table of the company (jurisdiction of the trip, year of each day, domestic or abroad by the destination country, the destination country row before the generic one), replacing the days already calculated. A day counts in full, in half or not at all by the hours away and the thresholds of the rate; meals and lodging provided by the employer reduce the allowance and the tax-free threshold. Fails when no rate exists or its currency differs from the trip.', summary: 'Calculate the per-diem days', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/TravelPerDiemRequest')), tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 200, description: 'Per-diem days', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TravelPerDiemDay')),
            new OA\Property(property: 'totals', type: 'object', properties: [new OA\Property(property: 'amount', type: 'string'), new OA\Property(property: 'tax_free_amount', type: 'string'), new OA\Property(property: 'taxable_amount', type: 'string'), new OA\Property(property: 'currency', type: 'string')]),
        ])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function calculate(PerDiemRequest $request, string $id): JsonResponse
    {
        $trip = $this->findTrip($id);
        $this->authorize('update', $trip);
        $days = $request->validated('days');
        $provided = null;

        if (is_array($days)) {
            $provided = [];

            foreach ($days as $day) {
                $provided[(string) $day['day']] = [
                    'breakfast_provided' => (bool) ($day['breakfast_provided'] ?? false),
                    'lunch_provided' => (bool) ($day['lunch_provided'] ?? false),
                    'dinner_provided' => (bool) ($day['dinner_provided'] ?? false),
                    'lodging_provided' => (bool) ($day['lodging_provided'] ?? false),
                ];
            }
        }

        return $this->respond($trip, $this->perDiem->calculate($trip, $provided));
    }

    /**
     * @param  iterable<int, TravelPerDiemDay>  $days
     */
    private function respond(TravelTrip $trip, iterable $days): JsonResponse
    {
        $amount = '0.00';
        $taxFree = '0.00';
        $taxable = '0.00';

        foreach ($days as $day) {
            $amount = bcadd($amount, (string) $day->amount, 2);
            $taxFree = bcadd($taxFree, (string) $day->tax_free_amount, 2);
            $taxable = bcadd($taxable, (string) $day->taxable_amount, 2);
        }

        return response()->json([
            'data' => PerDiemDayResource::collection($days)->resolve(),
            'totals' => ['amount' => $amount, 'tax_free_amount' => $taxFree, 'taxable_amount' => $taxable, 'currency' => $trip->currency],
        ]);
    }
}
