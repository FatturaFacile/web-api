<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class FleetRentalController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function vehicles(): JsonResponse
    {
        $vehicles = DB::table('rental_vehicles')
            ->join('vehicles', 'vehicles.id', '=', 'rental_vehicles.vehicle_id')
            ->where('rental_vehicles.tenant_id', $this->tenantContext->getTenantId())
            ->whereNull('rental_vehicles.deleted_at')
            ->select('rental_vehicles.*', 'vehicles.license_plate', 'vehicles.make', 'vehicles.model')
            ->get();

        return $this->successResponse(data: $vehicles, message: 'Rental fleet vehicles retrieved');
    }

    public function bookings(): JsonResponse
    {
        $bookings = DB::table('rental_bookings')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->whereNull('deleted_at')
            ->latest('start_date')
            ->paginate(25);

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

        $id = (string) Str::uuid();
        DB::table('damage_inspections')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantContext->getTenantId(),
            'rental_booking_id' => $validated['rental_booking_id'],
            'type' => 'check_in',
            'mileage' => $validated['mileage'],
            'fuel_level' => $validated['fuel_level'],
            'damage_markers' => json_encode($validated['damage_markers'] ?? []),
            'photos' => json_encode($validated['photos'] ?? []),
            'inspected_by_user_id' => $request->user()->id,
            'inspected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->successResponse(
            data: ['inspection_id' => $id, 'type' => 'check_in'],
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

        $id = (string) Str::uuid();
        DB::table('damage_inspections')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantContext->getTenantId(),
            'rental_booking_id' => $validated['rental_booking_id'],
            'type' => 'check_out',
            'mileage' => $validated['mileage'],
            'fuel_level' => $validated['fuel_level'],
            'damage_markers' => json_encode($validated['damage_markers'] ?? []),
            'photos' => json_encode($validated['photos'] ?? []),
            'inspected_by_user_id' => $request->user()->id,
            'inspected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->successResponse(
            data: ['inspection_id' => $id, 'type' => 'check_out'],
            message: 'Check-out inspection recorded',
            statusCode: Response::HTTP_CREATED
        );
    }
}
