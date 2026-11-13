<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FleetRental\DamageInspectionRequest;
use App\Http\Responses\ApiResponse;
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
        $vehicles = RentalVehicle::join('vehicles', 'vehicles.id', '=', 'rental_vehicles.vehicle_id')
            ->select('rental_vehicles.*', 'vehicles.license_plate', 'vehicles.make', 'vehicles.model')
            ->get();

        return $this->successResponse(data: $vehicles, message: 'Rental fleet vehicles retrieved');
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
        $bookings = RentalBooking::latest('start_date')->paginate(RequestedPerPage::resolve(25));

        return $this->successResponse(data: $bookings, message: 'Rental bookings retrieved');
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
