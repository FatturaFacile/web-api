<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\AdventureParks\Http\Controllers\Concerns\ResolvesParkContext;
use Modules\AdventureParks\Http\Requests\InvoiceActionRequest;
use Modules\AdventureParks\Http\Requests\ManageActionRequest;
use Modules\AdventureParks\Http\Requests\SaveSchoolBookingRequest;
use Modules\AdventureParks\Http\Requests\SchoolBookingIndexRequest;
use Modules\AdventureParks\Http\Requests\SchoolQuoteRequest;
use Modules\AdventureParks\Http\Resources\SchoolBookingResource;
use Modules\AdventureParks\Models\SchoolBooking;
use Modules\AdventureParks\Services\SchoolBookingService;
use OpenApi\Attributes as OA;

/**
 * School trips of the educational farm and their electronic invoice to the public administration.
 */
final class SchoolBookingController extends Controller
{
    use ApiResponse;
    use ResolvesParkContext;

    public function __construct(private readonly SchoolBookingService $bookings) {}

    #[OA\Get(path: '/api/v1/adventure-parks/school-bookings', operationId: 'adventureParksSchoolBookingsList', summary: 'List the school bookings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], responses: [new OA\Response(response: 200, description: 'School bookings')])]
    public function index(SchoolBookingIndexRequest $request): JsonResponse
    {
        return $this->successResponse(SchoolBookingResource::collection($this->bookings->list($request->validated()['status'] ?? null)));
    }

    #[OA\Post(path: '/api/v1/adventure-parks/school-bookings', operationId: 'adventureParksSchoolBookingsCreate', summary: 'Book a school trip (free teachers, picnic seats and total are computed)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], responses: [new OA\Response(response: 201, description: 'School booking created'), new OA\Response(response: 422, description: 'picnic_capacity_exceeded')])]
    public function store(SaveSchoolBookingRequest $request): JsonResponse
    {
        return $this->successResponse(new SchoolBookingResource($this->bookings->create($request->validated(), $this->userId($request))), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/adventure-parks/school-bookings/quote', operationId: 'adventureParksSchoolBookingsQuote', summary: 'Live quote of a school trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], responses: [new OA\Response(response: 200, description: 'Quote')])]
    public function quote(SchoolQuoteRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse($this->bookings->quote(
            (int) $data['paying_students'],
            (int) ($data['disabled_students'] ?? 0),
            (int) ($data['accompanying_teachers'] ?? 0),
            array_values((array) ($data['workshop_ids'] ?? [])),
        ));
    }

    #[OA\Get(path: '/api/v1/adventure-parks/school-bookings/{id}', operationId: 'adventureParksSchoolBookingsShow', summary: 'Show a school booking', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'School booking')])]
    public function show(string $id): JsonResponse
    {
        Gate::authorize('viewAny', SchoolBooking::class);

        return $this->successResponse(new SchoolBookingResource($this->bookings->fresh($id)));
    }

    #[OA\Put(path: '/api/v1/adventure-parks/school-bookings/{id}', operationId: 'adventureParksSchoolBookingsUpdate', summary: 'Update a school booking that is not invoiced', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'School booking updated')])]
    public function update(SaveSchoolBookingRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new SchoolBookingResource($this->bookings->update($id, $request->validated(), $this->userId($request))), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/adventure-parks/school-bookings/{id}', operationId: 'adventureParksSchoolBookingsDelete', summary: 'Delete a draft school booking', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'School booking deleted')])]
    public function destroy(string $id): JsonResponse
    {
        Gate::authorize('manage', SchoolBooking::class);
        $this->bookings->delete($id);

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Post(path: '/api/v1/adventure-parks/school-bookings/{id}/confirm', operationId: 'adventureParksSchoolBookingsConfirm', summary: 'Confirm a draft school booking', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'School booking confirmed')])]
    public function confirm(ManageActionRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new SchoolBookingResource($this->bookings->confirm($id)), $this->message('school_confirmed'));
    }

    #[OA\Post(path: '/api/v1/adventure-parks/school-bookings/{id}/complete', operationId: 'adventureParksSchoolBookingsComplete', summary: 'Mark a confirmed school booking as completed', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'School booking completed')])]
    public function complete(ManageActionRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new SchoolBookingResource($this->bookings->complete($id)), $this->message('school_completed'));
    }

    #[OA\Post(path: '/api/v1/adventure-parks/school-bookings/{id}/generate-fattura-pa', operationId: 'adventureParksSchoolBookingsGenerateFatturaPa', summary: 'Create the FatturaPA invoice of the trip (split payment, CIG / CUP, IPA office code)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'School booking invoiced'), new OA\Response(response: 422, description: 'already_invoiced or booking_not_confirmed')])]
    public function generateFatturaPa(InvoiceActionRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new SchoolBookingResource($this->bookings->generateInvoice($id, $this->userId($request))), $this->message('school_invoiced'));
    }
}
