<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FleetRental\DamageInspectionRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use App\Models\DamageInspection;
use App\Models\RentalBooking;
use App\Models\RentalVehicle;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class FleetRentalController extends Controller
{
    use ApiResponse;

    /** Same label as the `name` grid column of the contact. */
    private static function contactName(Contact $contact): string
    {
        $company = trim((string) $contact->company_name);
        $person = trim(trim((string) $contact->first_name).' '.trim((string) $contact->last_name));

        return $company !== '' ? $company : ($person !== '' ? $person : '-');
    }

    #[OA\Get(
        path: '/api/v1/rent/vehicles',
        operationId: 'getRentalVehicles',
        description: 'List rental fleet vehicles with availability status and vehicle details.',
        summary: 'List rental vehicles',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Fleet Rental'],
        responses: [
            new OA\Response(response: 200, description: 'Rental fleet vehicles retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function vehicles(): JsonResponse
    {
        $page = RentalVehicle::query()
            ->with(['vehicle', 'bookings' => fn ($query) => $query->where('status', 'active')->with('client')])
            ->orderByDesc('rental_vehicles.created_at')
            ->gridPaginate(RequestedPerPage::resolve(25));

        $rows = $page->getCollection()->map(fn (RentalVehicle $rental): array => [
            'id' => $rental->id,
            'plate' => $rental->vehicle?->license_plate,
            'make' => $rental->vehicle?->make,
            'model' => $rental->vehicle?->model,
            'type' => $rental->category,
            'client' => $rental->bookings->first()?->client ? self::contactName($rental->bookings->first()->client) : null,
            'returnDate' => $rental->bookings->max('end_date')?->toDateString(),
            'status' => $rental->is_available ? 'available' : 'rented',
            'daily_rate' => $rental->daily_rate,
            'current_mileage' => $rental->current_mileage,
        ])->values();

        return $this->successResponse(
            data: $rows,
            message: 'Rental fleet vehicles retrieved',
            meta: ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]
        );
    }

    #[OA\Get(
        path: '/api/v1/rent/bookings',
        operationId: 'getRentalBookings',
        description: 'List paginated rental reservations and lease bookings.',
        summary: 'List rental bookings',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Fleet Rental'],
        responses: [
            new OA\Response(response: 200, description: 'Rental bookings retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function bookings(): JsonResponse
    {
        $page = RentalBooking::query()
            ->with(['rentalVehicle.vehicle', 'client'])
            ->orderByDesc('rental_bookings.start_date')
            ->gridPaginate(RequestedPerPage::resolve(25));

        $rows = $page->getCollection()->map(fn (RentalBooking $booking): array => [
            'id' => $booking->id,
            'vehicle_id' => $booking->rental_vehicle_id,
            'vehicle_plate' => $booking->rentalVehicle?->vehicle?->license_plate,
            'vehicle_model' => $booking->rentalVehicle?->vehicle?->model,
            'client_id' => $booking->client_id,
            'client_name' => $booking->client ? self::contactName($booking->client) : null,
            'start_date' => $booking->start_date?->toIso8601String(),
            'end_date' => $booking->end_date?->toIso8601String(),
            'status' => $booking->status,
            'daily_rate' => $booking->rentalVehicle?->daily_rate,
            'total_amount' => $booking->total_estimated,
            'deposit_amount' => $booking->deposit_amount,
        ])->values();

        return $this->successResponse(
            data: $rows,
            message: 'Rental bookings retrieved',
            meta: ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]
        );
    }

    #[OA\Post(
        path: '/api/v1/rent/check-in-inspection',
        operationId: 'recordCheckInInspection',
        description: 'Record vehicle check-in damage inspection report and odometer reading upon return.',
        summary: 'Record check-in inspection',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Fleet Rental'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['rental_booking_id', 'mileage', 'fuel_level'],
                properties: [
                    new OA\Property(property: 'rental_booking_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'mileage', type: 'integer', example: 45200),
                    new OA\Property(property: 'fuel_level', type: 'string', example: '8/8'),
                    new OA\Property(property: 'damage_markers', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'photos', type: 'array', items: new OA\Items(type: 'string')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Check-in inspection recorded', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function checkInInspection(DamageInspectionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $inspection = DamageInspection::create([
            'rental_booking_id' => $validated['rental_booking_id'],
            'type' => 'check_in',
            'mileage' => $validated['mileage'],
            'fuel_level' => $validated['fuel_level'],
            'damage_markers' => $validated['damage_markers'] ?? [],
            'photos' => $validated['photos'] ?? [],
            'inspected_by_user_id' => $request->user()?->id,
            'inspected_at' => now(),
        ]);

        return $this->successResponse(
            data: ['inspection_id' => $inspection->id, 'type' => 'check_in'],
            message: 'Check-in inspection recorded',
            statusCode: Response::HTTP_CREATED
        );
    }

    #[OA\Post(
        path: '/api/v1/rent/check-out-inspection',
        operationId: 'recordCheckOutInspection',
        description: 'Record vehicle handover check-out inspection and baseline condition.',
        summary: 'Record check-out inspection',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Fleet Rental'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['rental_booking_id', 'mileage', 'fuel_level'],
                properties: [
                    new OA\Property(property: 'rental_booking_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'mileage', type: 'integer', example: 45000),
                    new OA\Property(property: 'fuel_level', type: 'string', example: '8/8'),
                    new OA\Property(property: 'damage_markers', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'photos', type: 'array', items: new OA\Items(type: 'string')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Check-out inspection recorded', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function checkOutInspection(DamageInspectionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $inspection = DamageInspection::create([
            'rental_booking_id' => $validated['rental_booking_id'],
            'type' => 'check_out',
            'mileage' => $validated['mileage'],
            'fuel_level' => $validated['fuel_level'],
            'damage_markers' => $validated['damage_markers'] ?? [],
            'photos' => $validated['photos'] ?? [],
            'inspected_by_user_id' => $request->user()?->id,
            'inspected_at' => now(),
        ]);

        return $this->successResponse(
            data: ['inspection_id' => $inspection->id, 'type' => 'check_out'],
            message: 'Check-out inspection recorded',
            statusCode: Response::HTTP_CREATED
        );
    }
}
