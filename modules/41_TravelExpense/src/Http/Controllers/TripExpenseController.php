<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\TravelExpense\Http\Controllers\Concerns\InteractsWithTravel;
use Modules\TravelExpense\Http\Requests\LinkExpenseRequest;
use Modules\TravelExpense\Services\ReconciliationService;
use Modules\TravelExpense\Services\TripExpenseService;
use OpenApi\Attributes as OA;

/**
 * The expenses of the expenses feature that settle a business trip.
 */
final class TripExpenseController extends Controller
{
    use InteractsWithTravel;

    public function __construct(private readonly TripExpenseService $expenses, private readonly ReconciliationService $reconciliation) {}

    #[OA\Get(path: '/api/v1/travel/trips/{id}/expenses', operationId: 'listTravelTripExpenses', description: 'The expenses linked to the trip with their status and whether they count in the reconciliation (approved, reimbursable, in the currency of the trip).', summary: 'Expenses linked to a trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 200, description: 'Linked expenses', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'expense_number', type: 'string'),
            new OA\Property(property: 'date', type: 'string', format: 'date'),
            new OA\Property(property: 'merchant_name', type: 'string', nullable: true),
            new OA\Property(property: 'amount', type: 'string'),
            new OA\Property(property: 'currency', type: 'string'),
            new OA\Property(property: 'status', type: 'string'),
            new OA\Property(property: 'counted', type: 'boolean'),
        ]))])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
    ])]
    public function index(string $id): JsonResponse
    {
        $trip = $this->findTrip($id);
        $this->authorize('view', $trip);

        return response()->json(['data' => $this->reconciliation->summarize($trip)['expenses']]);
    }

    #[OA\Post(path: '/api/v1/travel/trips/{id}/expenses', operationId: 'linkTravelTripExpense', description: 'Links an expense of the company to the trip. The expense must be the employee\'s own, reimbursable, not rejected, in the currency of the trip, dated from two weeks before the departure to two months after the return, and not linked to another trip.', summary: 'Link an expense to a trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TravelLinkExpenseRequest')), tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 201, description: 'Expense linked', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'trip_id', type: 'string', format: 'uuid'), new OA\Property(property: 'expense_id', type: 'string', format: 'uuid')])])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function store(LinkExpenseRequest $request, string $id): JsonResponse
    {
        $trip = $this->findTrip($id);
        $this->authorize('update', $trip);
        $link = $this->expenses->link($trip, (string) $request->validated('expense_id'));

        return response()->json(['data' => ['trip_id' => $link->trip_id, 'expense_id' => $link->expense_id]], Response::HTTP_CREATED);
    }

    #[OA\Delete(path: '/api/v1/travel/trips/{id}/expenses/{itemId}', operationId: 'unlinkTravelTripExpense', description: 'Detaches an expense from the trip; the expense itself is untouched.', summary: 'Unlink an expense from a trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId'), new OA\Parameter(ref: '#/components/parameters/TravelItemId')], responses: [
        new OA\Response(response: 204, description: 'Expense unlinked'),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function destroy(string $id, string $itemId): Response
    {
        $trip = $this->findTrip($id);
        $this->authorize('update', $trip);
        $this->expenses->unlink($trip, $itemId);

        return response()->noContent();
    }
}
