<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\TravelExpense\Http\Controllers\Concerns\InteractsWithTravel;
use Modules\TravelExpense\Http\Requests\AdvancePaidRequest;
use Modules\TravelExpense\Http\Requests\ApproveTripRequest;
use Modules\TravelExpense\Http\Requests\RejectTripRequest;
use Modules\TravelExpense\Http\Resources\TripResource;
use Modules\TravelExpense\Services\TripService;
use OpenApi\Attributes as OA;

/**
 * The approval flow and settlement of a business trip: draft, submitted, approved or rejected, completed, reconciled.
 */
final class TripWorkflowController extends Controller
{
    use InteractsWithTravel;

    public function __construct(private readonly TripService $trips) {}

    #[OA\Post(path: '/api/v1/travel/trips/{id}/submit', operationId: 'submitTravelTrip', description: 'Sends a draft trip to approval. The trip must not overlap another submitted, approved, completed or reconciled trip of the employee, and the advance cannot exceed the estimated budget.', summary: 'Submit a trip for approval', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 200, description: 'Trip submitted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelTrip')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function submit(string $id): TripResource
    {
        $trip = $this->findTrip($id);
        $this->authorize('update', $trip);

        return new TripResource($this->trips->submit($trip));
    }

    #[OA\Post(path: '/api/v1/travel/trips/{id}/withdraw', operationId: 'withdrawTravelTrip', description: 'Takes a submitted trip back to draft, before it is decided.', summary: 'Withdraw a submitted trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 200, description: 'Trip withdrawn', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelTrip')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function withdraw(string $id): TripResource
    {
        $trip = $this->findTrip($id);
        $this->authorize('update', $trip);

        return new TripResource($this->trips->withdraw($trip));
    }

    #[OA\Post(path: '/api/v1/travel/trips/{id}/approve', operationId: 'approveTravelTrip', description: 'Approves a submitted trip, optionally changing the advance (never above the estimated budget). Owners, administrators, HR, accountants and members granted travel.approve approve; nobody approves their own trip except the owner of the company.', summary: 'Approve a trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/TravelApproveRequest')), tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 200, description: 'Trip approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelTrip')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function approve(ApproveTripRequest $request, string $id): TripResource
    {
        $trip = $this->findTrip($id);
        $this->authorize('approve', $trip);
        $advance = $request->validated('advance_amount');

        return new TripResource($this->trips->approve($trip, $this->userId(), $advance === null ? null : (string) $advance));
    }

    #[OA\Post(path: '/api/v1/travel/trips/{id}/reject', operationId: 'rejectTravelTrip', description: 'Rejects a submitted trip with the reason the employee reads. Same approvers as approve.', summary: 'Reject a trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TravelRejectRequest')), tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 200, description: 'Trip rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelTrip')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function reject(RejectTripRequest $request, string $id): TripResource
    {
        $trip = $this->findTrip($id);
        $this->authorize('approve', $trip);

        return new TripResource($this->trips->reject($trip, $this->userId(), (string) $request->validated('reason')));
    }

    #[OA\Post(path: '/api/v1/travel/trips/{id}/reopen', operationId: 'reopenTravelTrip', description: 'Takes a rejected trip back to draft so that it can be corrected and submitted again.', summary: 'Reopen a rejected trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 200, description: 'Trip reopened', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelTrip')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function reopen(string $id): TripResource
    {
        $trip = $this->findTrip($id);
        $this->authorize('update', $trip);

        return new TripResource($this->trips->reopen($trip));
    }

    #[OA\Post(path: '/api/v1/travel/trips/{id}/complete', operationId: 'completeTravelTrip', description: 'Marks an approved trip as done. The trip must have started (company time zone).', summary: 'Complete a trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 200, description: 'Trip completed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelTrip')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function complete(string $id): TripResource
    {
        $trip = $this->findTrip($id);
        $this->authorize('update', $trip);

        return new TripResource($this->trips->complete($trip));
    }

    #[OA\Post(path: '/api/v1/travel/trips/{id}/advance-paid', operationId: 'markTravelAdvancePaid', description: 'Records the date the advance was paid to the employee. Only an approved or completed trip with an advance; the advance counts in the reconciliation from then on. Finance only (owners, administrators, accountants, travel.reconcile).', summary: 'Record the payment of the advance', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TravelAdvancePaidRequest')), tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 200, description: 'Advance recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelTrip')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function advancePaid(AdvancePaidRequest $request, string $id): TripResource
    {
        $trip = $this->findTrip($id);
        $this->authorize('settle', $trip);

        return new TripResource($this->trips->markAdvancePaid($trip, (string) $request->validated('paid_at')));
    }

    #[OA\Post(path: '/api/v1/travel/trips/{id}/reconcile', operationId: 'reconcileTravelTrip', description: 'Settles a completed trip: per diem (calculated now when missing and a rate table exists), mileage and approved reimbursable expenses against the advance paid. The balance is frozen on the trip: positive owed to the employee, negative owed back. Linked expenses still in draft or submitted block it. Finance only.', summary: 'Reconcile the advance of a trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 200, description: 'Trip reconciled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelTrip')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function reconcile(string $id): TripResource
    {
        $trip = $this->findTrip($id);
        $this->authorize('settle', $trip);

        return new TripResource($this->trips->reconcile($trip, $this->userId()));
    }
}
