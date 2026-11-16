<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\TravelExpense\Http\Controllers\Concerns\InteractsWithTravel;
use Modules\TravelExpense\Http\Requests\LegRequest;
use Modules\TravelExpense\Http\Resources\LegResource;
use Modules\TravelExpense\Models\TravelLeg;
use Modules\TravelExpense\Services\ItineraryService;
use OpenApi\Attributes as OA;

/**
 * The itinerary legs of a business trip.
 */
final class LegController extends Controller
{
    use InteractsWithTravel;

    public function __construct(private readonly ItineraryService $itinerary) {}

    #[OA\Post(path: '/api/v1/travel/trips/{id}/legs', operationId: 'storeTravelLeg', description: 'Adds a flight, train, car or hotel leg with its booked cost, in the currency of the trip. A hotel needs check-in and check-out. Allowed while the trip is a draft, approved or completed.', summary: 'Add an itinerary leg', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TravelLegRequest')), tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 201, description: 'Leg added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelLeg')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function store(LegRequest $request, string $id): JsonResponse
    {
        $trip = $this->findTrip($id);
        $this->authorize('update', $trip);

        return (new LegResource($this->itinerary->addLeg($trip, $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/travel/trips/{id}/legs/{itemId}', operationId: 'updateTravelLeg', description: 'Replaces the data of a leg.', summary: 'Update an itinerary leg', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TravelLegRequest')), tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId'), new OA\Parameter(ref: '#/components/parameters/TravelItemId')], responses: [
        new OA\Response(response: 200, description: 'Leg updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelLeg')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function update(LegRequest $request, string $id, string $itemId): LegResource
    {
        $trip = $this->findTrip($id);
        $this->authorize('update', $trip);
        $leg = TravelLeg::query()->where('trip_id', $trip->id)->findOrFail($itemId);

        return new LegResource($this->itinerary->updateLeg($trip, $leg, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/travel/trips/{id}/legs/{itemId}', operationId: 'deleteTravelLeg', description: 'Removes a leg from the itinerary.', summary: 'Remove an itinerary leg', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId'), new OA\Parameter(ref: '#/components/parameters/TravelItemId')], responses: [
        new OA\Response(response: 204, description: 'Leg removed'),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function destroy(string $id, string $itemId): Response
    {
        $trip = $this->findTrip($id);
        $this->authorize('update', $trip);
        $this->itinerary->removeLeg($trip, TravelLeg::query()->where('trip_id', $trip->id)->findOrFail($itemId));

        return response()->noContent();
    }
}
