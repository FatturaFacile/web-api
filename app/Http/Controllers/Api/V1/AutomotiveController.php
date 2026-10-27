<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\TireHotelRecord;
use App\Models\Vehicle;
use App\Models\VehicleInspection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AutomotiveController extends Controller
{
    use ApiResponse;

    public function vehicles(): JsonResponse
    {
        $vehicles = Vehicle::latest()->paginate(25);

        return $this->successResponse(data: $vehicles, message: 'Vehicles retrieved');
    }

    public function tireHotelCheckIn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => ['required', 'uuid'],
            'season' => ['required', 'in:summer,winter,all_season'],
            'brand' => ['required', 'string'],
            'model' => ['nullable', 'string'],
            'size' => ['required', 'string'],
            'dot' => ['nullable', 'string'],
            'front_left_tread_mm' => ['required', 'numeric', 'between:0,15'],
            'front_right_tread_mm' => ['required', 'numeric', 'between:0,15'],
            'rear_left_tread_mm' => ['required', 'numeric', 'between:0,15'],
            'rear_right_tread_mm' => ['required', 'numeric', 'between:0,15'],
            'warehouse_shelf_location' => ['required', 'string'],
        ]);

        $record = TireHotelRecord::create([
            ...$validated,
            'status' => 'stored',
        ]);

        return $this->successResponse(
            data: ['id' => $record->id, 'status' => 'stored'],
            message: 'Tire set checked into hotel',
            statusCode: Response::HTTP_CREATED
        );
    }

    public function tireHotelCheckOut(Request $request, string $id): JsonResponse
    {
        $record = TireHotelRecord::findOrFail($id);
        $record->update([
            'status' => 'fitted',
        ]);

        return $this->successResponse(message: 'Tire set checked out and fitted');
    }

    public function seasonalSwitchPlan(): JsonResponse
    {
        $plan = TireHotelRecord::join('vehicles', 'vehicles.id', '=', 'tire_hotel_records.vehicle_id')
            ->where('tire_hotel_records.status', 'stored')
            ->select('tire_hotel_records.*', 'vehicles.license_plate', 'vehicles.make', 'vehicles.model as vehicle_model')
            ->get();

        return $this->successResponse(data: $plan, message: 'Seasonal switch plan generated');
    }

    public function inspectionsDueList(): JsonResponse
    {
        $inspections = VehicleInspection::join('vehicles', 'vehicles.id', '=', 'vehicle_inspections.vehicle_id')
            ->where('vehicle_inspections.result', 'pending')
            ->select('vehicle_inspections.*', 'vehicles.license_plate', 'vehicles.make')
            ->get();

        return $this->successResponse(data: $inspections, message: 'Inspections due list');
    }

    public function completeInspection(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'result' => ['required', 'in:passed,failed,conditional'],
            'certificate_number' => ['nullable', 'string'],
            'inspector_notes' => ['nullable', 'string'],
        ]);

        $inspection = VehicleInspection::findOrFail($id);
        $inspection->update([
            'passed_date' => now()->toDateString(),
            'result' => $validated['result'],
            'certificate_number' => $validated['certificate_number'] ?? null,
            'inspector_notes' => $validated['inspector_notes'] ?? null,
        ]);

        return $this->successResponse(message: 'Inspection completed');
    }
}
