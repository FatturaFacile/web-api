<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\TravelExpense\Http\Controllers\Concerns\InteractsWithTravel;
use Modules\TravelExpense\Http\Requests\MileageClaimRequest;
use Modules\TravelExpense\Http\Resources\MileageClaimResource;
use Modules\TravelExpense\Models\TravelMileageClaim;
use Modules\TravelExpense\Services\MileageService;
use OpenApi\Attributes as OA;

/**
 * The mileage claims of a business trip.
 */
final class MileageClaimController extends Controller
{
    use InteractsWithTravel;

    public function __construct(private readonly MileageService $mileage) {}

    #[OA\Post(path: '/api/v1/travel/trips/{id}/mileage-claims', operationId: 'storeTravelMileageClaim', description: 'Claims a journey with the own vehicle within the dates of the trip: kilometres (doubled for a round trip) at the rate of the company for the jurisdiction of the trip, the year of the journey and the vehicle type. The rate is snapshotted in the claim. Fails when no rate exists or its currency differs from the trip.', summary: 'Claim mileage', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TravelMileageClaimRequest')), tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 201, description: 'Claim created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelMileageClaim')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function store(MileageClaimRequest $request, string $id): JsonResponse
    {
        $trip = $this->findTrip($id);
        $this->authorize('update', $trip);

        return (new MileageClaimResource($this->mileage->create($trip, $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/travel/trips/{id}/mileage-claims/{itemId}', operationId: 'updateTravelMileageClaim', description: 'Replaces a claim; the amount is calculated again with the rate in force.', summary: 'Update a mileage claim', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TravelMileageClaimRequest')), tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId'), new OA\Parameter(ref: '#/components/parameters/TravelItemId')], responses: [
        new OA\Response(response: 200, description: 'Claim updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelMileageClaim')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function update(MileageClaimRequest $request, string $id, string $itemId): MileageClaimResource
    {
        $trip = $this->findTrip($id);
        $this->authorize('update', $trip);
        $claim = TravelMileageClaim::query()->where('trip_id', $trip->id)->findOrFail($itemId);

        return new MileageClaimResource($this->mileage->update($trip, $claim, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/travel/trips/{id}/mileage-claims/{itemId}', operationId: 'deleteTravelMileageClaim', description: 'Removes a mileage claim.', summary: 'Remove a mileage claim', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId'), new OA\Parameter(ref: '#/components/parameters/TravelItemId')], responses: [
        new OA\Response(response: 204, description: 'Claim removed'),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function destroy(string $id, string $itemId): Response
    {
        $trip = $this->findTrip($id);
        $this->authorize('update', $trip);
        $this->mileage->delete($trip, TravelMileageClaim::query()->where('trip_id', $trip->id)->findOrFail($itemId));

        return response()->noContent();
    }
}
