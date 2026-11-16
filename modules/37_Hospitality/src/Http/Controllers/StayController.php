<?php

declare(strict_types=1);

namespace Modules\Hospitality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Hospitality\Http\Requests\GroupBookingRequest;
use Modules\Hospitality\Http\Requests\LodgingRequest;
use Modules\Hospitality\Http\Resources\HospitalityResource;
use Modules\Hospitality\Models\FolioEntry;
use Modules\Hospitality\Models\Stay;
use Modules\Hospitality\Services\FolioInvoicingService;
use Modules\Hospitality\Services\LodgingService;
use OpenApi\Attributes as OA;

/**
 * Lodging stays: booking, check-in with room assignment, the guest folio and check-out.
 */
final class StayController extends Controller
{
    public function __construct(private readonly LodgingService $lodging, private readonly FolioInvoicingService $folios) {}

    #[OA\Get(path: '/api/v1/hospitality/stays', operationId: 'listHospitalityStays', description: 'Stays by check-in date. Filters: status, date (stays that include the night of that day), room_type_id.', summary: 'List stays', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Hospitality'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['booked', 'checked_in', 'checked_out', 'cancelled', 'no_show'])),
        new OA\Parameter(name: 'date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'room_type_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Stays', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordCollection')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Stay::class);

        return HospitalityResource::collection(
            Stay::query()->with(['roomType', 'accommodation'])
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($request->filled('room_type_id'), static fn ($query) => $query->where('room_type_id', (string) $request->query('room_type_id')))
                ->when($request->filled('date'), static fn ($query) => $query->where('check_in_date', '<=', (string) $request->query('date'))->where('check_out_date', '>', (string) $request->query('date')))
                ->orderBy('check_in_date')->orderBy('number')->gridPaginate(RequestedPerPage::resolve(25))
        );
    }

    #[OA\Post(path: '/api/v1/hospitality/stays', operationId: 'storeHospitalityStay', description: 'Books a room type for an interval: refused when no room of the type is free on every night, or the party exceeds its capacity. The nightly rates (chosen plan, best seasonal plan or base rate) are fixed at booking.', summary: 'Book a stay', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityStayRequest')), tags: ['Hospitality'], responses: [
        new OA\Response(response: 201, description: 'Stay booked', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function store(LodgingRequest $request): JsonResponse
    {
        $this->authorize('create', Stay::class);

        return (new HospitalityResource($this->lodging->book($request->validated())->load('roomType')))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/hospitality/stays/{id}', operationId: 'showHospitalityStay', description: 'A stay with its room type, assigned room, folio lines and the folio balance (charges minus payments).', summary: 'Show a stay', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Stay', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
    ])]
    public function show(string $id): HospitalityResource
    {
        return new HospitalityResource($this->stay($id, 'view')->load(['roomType', 'accommodation', 'folioEntries']));
    }

    #[OA\Post(path: '/api/v1/hospitality/stays/{id}/check-in', operationId: 'checkInHospitalityStay', description: 'Checks the guest in on or after the arrival date. The room is the given one or the first free clean room of the booked type; the nightly room charges (and the city tax when a rate per person and night is given) are posted to the folio.', summary: 'Check in', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityCheckInRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Stay checked in', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function checkIn(LodgingRequest $request, string $id): HospitalityResource
    {
        $stay = $this->lodging->checkIn($this->stay($id, 'update'), $request->validated());

        return new HospitalityResource($stay->load(['roomType', 'accommodation', 'folioEntries']));
    }

    #[OA\Post(path: '/api/v1/hospitality/stays/{id}/check-out', operationId: 'checkOutHospitalityStay', description: 'Checks the guest out. Refused while the folio balance is positive. The room becomes dirty and a check-out clean-up task is created.', summary: 'Check out', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Stay checked out', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function checkOut(string $id): HospitalityResource
    {
        return new HospitalityResource($this->lodging->checkOut($this->stay($id, 'update'))->load(['roomType', 'accommodation', 'folioEntries']));
    }

    #[OA\Post(path: '/api/v1/hospitality/stays/{id}/cancel', operationId: 'cancelHospitalityStay', description: 'Cancels a booked stay and frees its room type for the nights.', summary: 'Cancel a stay', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Stay cancelled', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function cancel(string $id): HospitalityResource
    {
        return new HospitalityResource($this->lodging->cancel($this->stay($id, 'update')));
    }

    #[OA\Post(path: '/api/v1/hospitality/stays/{id}/no-show', operationId: 'noShowHospitalityStay', description: 'Marks a booked stay as a no-show and frees its room type.', summary: 'Mark a no-show', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Stay marked no-show', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function noShow(string $id): HospitalityResource
    {
        return new HospitalityResource($this->lodging->cancel($this->stay($id, 'update'), true));
    }

    #[OA\Post(path: '/api/v1/hospitality/stays/{id}/charges', operationId: 'storeHospitalityFolioCharge', description: 'Posts a charge (extras, restaurant, minibar) to the folio of a booked or checked-in stay.', summary: 'Post a folio charge', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityFolioChargeRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 201, description: 'Charge posted', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function storeCharge(LodgingRequest $request, string $id): JsonResponse
    {
        return (new HospitalityResource($this->lodging->postCharge($this->stay($id, 'update'), $request->validated())))->response()->setStatusCode(201);
    }

    #[OA\Post(path: '/api/v1/hospitality/stays/{id}/payments', operationId: 'storeHospitalityFolioPayment', description: 'Posts a payment to the folio of a booked or checked-in stay.', summary: 'Post a folio payment', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityFolioPaymentRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 201, description: 'Payment posted', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function storePayment(LodgingRequest $request, string $id): JsonResponse
    {
        return (new HospitalityResource($this->lodging->postPayment($this->stay($id, 'update'), $request->validated())))->response()->setStatusCode(201);
    }

    #[OA\Post(path: '/api/v1/hospitality/folio-entries/{id}/void', operationId: 'voidHospitalityFolioEntry', description: 'Voids a folio line of a stay whose folio is still open. Reserved to managers; the line stays for the audit trail.', summary: 'Void a folio line', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Line voided', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function voidEntry(string $id): HospitalityResource
    {
        $entry = FolioEntry::query()->findOrFail($id);
        $this->authorize('manage', $entry);

        return new HospitalityResource($this->lodging->voidEntry($entry));
    }

    #[OAGet(path: '/api/v1/hospitality/stays/{id}/folio-statement', operationId: 'hospitalityFolioStatement', description: 'The statement of the guest folio of a stay: charges, payments, totals per category and the balance (charges less payments). Voided lines are left out. It is available in every status, also before check-out.', summary: 'Folio statement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Hospitality'], parameters: [new OAParameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OAResponse(response: 200, description: 'Folio statement', content: new OAJsonContent(ref: '#/components/schemas/HospitalityFolioStatementResponse')),
        new OAResponse(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OAResponse(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OAResponse(ref: '#/components/responses/HospitalityNotFound', response: 404),
    ])]
    public function folioStatement(string $id): JsonResponse
    {
        return response()->json(['data' => $this->folios->statement($this->stay($id, 'view'))]);
    }

    #[OAPost(path: '/api/v1/hospitality/stays/{id}/invoice', operationId: 'invoiceHospitalityFolio', description: 'Invoices the charges of a checked-out stay to a customer, once, through the sales invoicing service (numbering, VAT and ledger posting). Each charge becomes a line with the tax rate of its category, else the default rate. The folio lines carry no tax rate: the caller chooses them. Reserved to managers.', summary: 'Invoice the folio', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OARequestBody(required: true, content: new OAJsonContent(ref: '#/components/schemas/HospitalityFolioInvoiceRequest')), tags: ['Hospitality'], parameters: [new OAParameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OAResponse(response: 201, description: 'Folio invoiced', content: new OAJsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OAResponse(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OAResponse(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OAResponse(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OAResponse(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function invoice(GroupBookingRequest $request, string $id): JsonResponse
    {
        $stay = $this->stay($id, 'manage');

        return (new HospitalityResource($this->folios->invoice($stay, $request->validated())))->response()->setStatusCode(201);
    }

    #[OAGet(path: '/api/v1/hospitality/availability-grid', operationId: 'hospitalityAvailabilityGrid', description: 'Availability night by night of every active room type for the calendar grid: rooms in total, booked, held for open group blocks and still available. The interval is at most 62 days.', summary: 'Availability grid', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Hospitality'], parameters: [
        new OAParameter(name: 'from', in: 'query', required: true, schema: new OASchema(type: 'string', format: 'date')),
        new OAParameter(name: 'to', in: 'query', required: true, description: 'Exclusive end, after from', schema: new OASchema(type: 'string', format: 'date')),
    ], responses: [
        new OAResponse(response: 200, description: 'Availability grid', content: new OAJsonContent(ref: '#/components/schemas/HospitalityAvailabilityGridResponse')),
        new OAResponse(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OAResponse(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OAResponse(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function availabilityGrid(GroupBookingRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Stay::class);

        return response()->json(['data' => $this->lodging->availabilityGrid((string) $request->validated('from'), (string) $request->validated('to'))]);
    }

    private function stay(string $id, string $ability): Stay
    {
        $stay = Stay::query()->findOrFail($id);
        $this->authorize($ability, $stay);

        return $stay;
    }
}
