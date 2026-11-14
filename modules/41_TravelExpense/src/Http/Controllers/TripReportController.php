<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\TravelExpense\Http\Controllers\Concerns\InteractsWithTravel;
use Modules\TravelExpense\Http\Resources\TripResource;
use Modules\TravelExpense\Services\ReconciliationService;
use Modules\TravelExpense\Services\TripReportService;
use OpenApi\Attributes as OA;

/**
 * Reconciliation summary and report of a business trip.
 */
final class TripReportController extends Controller
{
    use InteractsWithTravel;

    public function __construct(private readonly ReconciliationService $reconciliation, private readonly TripReportService $reports) {}

    #[OA\Get(path: '/api/v1/travel/trips/{id}/reconciliation', operationId: 'showTravelReconciliation', description: 'Live reconciliation of the trip: per diem, mileage and approved reimbursable expenses against the advance paid, with the balance owed either way and the linked expenses that are counted or blocking.', summary: 'Reconciliation summary of a trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 200, description: 'Reconciliation', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelReconciliation')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
    ])]
    public function reconciliation(string $id): JsonResponse
    {
        $trip = $this->findTrip($id);
        $this->authorize('view', $trip);

        return response()->json(['data' => $this->reconciliation->summarize($trip)]);
    }

    #[OA\Get(path: '/api/v1/travel/trips/{id}/report', operationId: 'showTravelTripReport', description: 'The trip report: the trip with itinerary, per diem and mileage, the plan (estimated budget, booked itinerary) against the actual (per diem, mileage, approved expenses) with the variance, and the reconciliation. Once reconciled the frozen figures are reported.', summary: 'Trip report', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 200, description: 'Report', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelTripReport')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
    ])]
    public function report(string $id): JsonResponse
    {
        $trip = $this->findTrip($id);
        $this->authorize('view', $trip);

        return response()->json(['data' => ['trip' => (new TripResource($trip->load(['employee', 'legs', 'perDiemDays', 'mileageClaims'])))->resolve()] + $this->reports->build($trip)]);
    }
}
