<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\DamageInspection;
use App\Models\RentalBooking;
use App\Models\RentalVehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FleetRentalController extends Controller
{
    use ApiResponse;

    public function vehicles(): JsonResponse
    {
        $vehicles = RentalVehicle::join('vehicles', 'vehicles.id', '=', 'rental_vehicles.vehicle_id')
            ->select('rental_vehicles.*', 'vehicles.license_plate', 'vehicles.make', 'vehicles.model')
            ->get();

        return $this->successResponse(data: $vehicles, message: 'Rental fleet vehicles retrieved');
    }

    public function bookings(): JsonResponse
    {
        $bookings = RentalBooking::latest('start_date')->paginate(25);

        return $this->successResponse(data: $bookings, message: 'Rental bookings retrieved');
    }

    public function checkInInspection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rental_booking_id' => ['required', 'uuid'],
            'mileage' => ['required', 'integer'],
            'fuel_level' => ['required', 'string'],
            'damage_markers' => ['nullable', 'array'],
            'photos' => ['nullable', 'array'],
        ]);

        $inspection = DamageInspection::create([
            'rental_booking_id' => $validated['rental_booking_id'],
            'type' => 'check_in',
            'mileage' => $validated['mileage'],
            'fuel_level' => $validated['fuel_level'],
            'damage_markers' => $validated['damage_markers'] ?? [],
            'photos' => $validated['photos'] ?? [],
            'inspected_by_user_id' => $request->user()->id,
            'inspected_at' => now(),
        ]);

        return $this->successResponse(
            data: ['inspection_id' => $inspection->id, 'type' => 'check_in'],
            message: 'Check-in inspection recorded',
            statusCode: Response::HTTP_CREATED
        );
    }

    public function checkOutInspection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rental_booking_id' => ['required', 'uuid'],
            'mileage' => ['required', 'integer'],
            'fuel_level' => ['required', 'string'],
            'damage_markers' => ['nullable', 'array'],
            'photos' => ['nullable', 'array'],
        ]);

        $inspection = DamageInspection::create([
            'rental_booking_id' => $validated['rental_booking_id'],
            'type' => 'check_out',
            'mileage' => $validated['mileage'],
            'fuel_level' => $validated['fuel_level'],
            'damage_markers' => $validated['damage_markers'] ?? [],
            'photos' => $validated['photos'] ?? [],
            'inspected_by_user_id' => $request->user()->id,
            'inspected_at' => now(),
        ]);

        return $this->successResponse(
            data: ['inspection_id' => $inspection->id, 'type' => 'check_out'],
            message: 'Check-out inspection recorded',
            statusCode: Response::HTTP_CREATED
        );
    }
}
